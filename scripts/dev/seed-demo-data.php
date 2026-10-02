<?php

/**
 * Seeds a small, realistic dataset for UI sweeps (local/CI test DB only).
 * Idempotent: skips when the demo company already exists.
 *
 * Usage: APP_ENV=test DATABASE_URL=mysql://... php scripts/dev/seed-demo-data.php
 */

use App\Entity\Activity;
use App\Entity\Company;
use App\Entity\Contact;
use App\Entity\Lead;
use App\Entity\Task;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

require __DIR__ . '/../../vendor/autoload.php';

putenv('APP_ENV=test');
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
putenv('MAILER_DSN=null://null');
$_SERVER['MAILER_DSN'] = $_ENV['MAILER_DSN'] = 'null://null';
putenv('LOCK_DSN=flock');
$_SERVER['LOCK_DSN'] = $_ENV['LOCK_DSN'] = 'flock';

$kernel = new App\Kernel('test', false);
$kernel->boot();

/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();

$marker = $em->getRepository(Company::class)->findOneBy(['name' => 'Atlas Components SARL']);
if ($marker !== null && $em->getRepository(Contact::class)->count([]) > 0) {
    echo "demo dataset already present\n";
    exit(0);
}

// Half-seeded by an interrupted earlier run: drop the bare companies and redo.
if ($marker !== null) {
    $staleNames = [
        'Atlas Components SARL', 'Delta Precision Tunisia', 'Gulf Industrial Supply',
        'Medina Packaging Co', 'Sfax Metalworks', 'Nord Electrique Maroc',
        'Iberia Fasteners SL', 'Anadolu Polimer',
    ];
    while (null !== ($stale = $em->getRepository(Company::class)->findOneBy(['name' => $staleNames]))) {
        $em->remove($stale);
        $em->flush();
        $em->clear();
    }
}

$user = $em->getRepository(User::class)->findOneBy(['email' => 'demo.admin@starz.local'])
    ?? $em->getRepository(User::class)->findOneBy([], ['id' => 'ASC']);

$companies = [
    ['Atlas Components SARL', 'Automotive', 'active', 'A', 'Morocco', 'MA-TNG', 'SQL'],
    ['Delta Precision Tunisia', 'Consumer Electronics', 'approved', 'B', 'Tunisia', 'TN-NBE', 'MQL'],
    ['Gulf Industrial Supply', 'Aerospace', 'active', 'A', 'Saudi Arabia', 'SA-01', 'Proposal'],
    ['Medina Packaging Co', 'Other', 'discovered', 'C', 'Morocco', 'MA-CAS', 'Prospect'],
    ['Sfax Metalworks', 'Industrial', 'active', 'B', 'Tunisia', 'TN-SFA', 'SQO'],
    ['Nord Electrique Maroc', 'Power Electronics', 'approved', 'C', 'Morocco', 'MA-RBA', 'SQL'],
    ['Iberia Fasteners SL', 'Automotive', 'active', 'A', 'Spain', 'ES-CT', 'Award'],
    ['Anadolu Polimer', 'Industrial', 'discovered', 'C', 'Turkey', 'TR-34', 'Prospect'],
];

$made = [];
foreach ($companies as [$name, $sector, $status, $tier, $country, $region, $stage]) {
    $c = new Company();
    $c->setName($name);
    $c->setSector($sector);
    $c->setCompanyStatus($status);
    $c->setAccountTier($tier);
    $c->setCountry($country);
    $c->setRegion($region);
    $c->setPipelineStage($stage);
    $c->setWebsite('https://example.com/' . strtolower(str_replace(' ', '-', $name)));
    $em->persist($c);
    $made[$name] = $c;
}
$em->flush();

$contacts = [
    ['Atlas Components SARL', 'Youssef', 'El Amrani', 'Procurement Lead', 'youssef@example.com', '+212 661 000 001'],
    ['Atlas Components SARL', 'Salma', 'Bennani', 'Quality Engineer', 'salma@example.com', '+212 661 000 002'],
    ['Delta Precision Tunisia', 'Karim', 'Trabelsi', 'CTO', 'karim@example.com', '+216 71 000 003'],
    ['Gulf Industrial Supply', 'Faisal', 'Al-Harbi', 'Buyer', 'faisal@example.com', '+966 50 000 004'],
    ['Sfax Metalworks', 'Nadia', 'Gharbi', 'Ops Manager', 'nadia@example.com', '+216 74 000 005'],
    ['Iberia Fasteners SL', 'Marta', 'Sanz', 'Purchasing', 'marta@example.com', '+34 600 000 006'],
];
foreach ($contacts as [$companyName, $first, $last, $jobTitle, $email, $phone]) {
    $ct = new Contact();
    $ct->setCompany($made[$companyName]);
    $ct->setFirstName($first);
    $ct->setLastName($last);
    $ct->setJobTitle($jobTitle);
    $ct->setEmail($email);
    $ct->setPhone($phone);
    $em->persist($ct);
}

$leads = [
    ['CNC machining enquiry — 5k units', 'Delta Precision Tunisia', Lead::STATUS_APPROVED],
    ['Stamping dies RFQ', 'Gulf Industrial Supply', Lead::STATUS_PENDING],
    ['Prototype brackets', 'Anadolu Polimer', Lead::STATUS_PENDING],
    ['Annual supply contract', 'Iberia Fasteners SL', Lead::STATUS_APPROVED],
];
foreach ($leads as [$title, $companyName, $status]) {
    $l = new Lead();
    $l->setCompanyName($title);
    $l->setWebsiteRoot('https://example.com');
    $l->setReviewStatus($status);
    $em->persist($l);
}

$activityTypes = ['call', 'email', 'meeting', 'site_visit'];
$i = 0;
foreach ($made as $company) {
    if ($i >= 5) {
        break;
    }
    $a = new Activity();
    $a->setCompany($company);
    $a->setType($activityTypes[$i % count($activityTypes)]);
    $a->setSubject('Quotation follow-up');
    $a->setNotes('Follow-up on quotation line items and delivery windows for the Q4 frame agreement.');
    $a->setActivityDate(new \DateTimeImmutable('-' . ($i * 3 + 1) . ' days'));
    if ($user !== null) {
        $a->setUser($user);
    }
    $em->persist($a);
    $i++;
}

$tasks = [
    ['Chase ARIBA responses', 'high', Task::STATUS_TODO],
    ['Prepare Coupa catalog sheet', 'medium', Task::STATUS_IN_PROGRESS],
    ['Book freight audit slot', 'low', Task::STATUS_DONE],
];
foreach ($tasks as [$title, $priority, $status]) {
    $t = new Task();
    $t->setTitle($title);
    $t->setDescription('Generated for the UI sweep dataset.');
    $t->setPriority($priority);
    $t->setStatus($status);
    if ($user !== null) {
        $t->setAssignedTo($user);
    $t->setCreatedBy($user);
    }
    $em->persist($t);
}

$em->flush();
echo 'seeded: ' . count($made) . " companies + contacts/leads/activities/tasks\n";
