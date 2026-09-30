<?php

/**
 * Seeds the deterministic demo account the Playwright mutation flows log
 * in with. Idempotent: an existing account is left untouched.
 *
 * Usage: php tests/e2e/seed.php   (APP_ENV=test + DATABASE_URL to the *_test DB)
 */

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

require __DIR__ . '/../../vendor/autoload.php';

putenv('APP_ENV=test');
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
putenv('MAILER_DSN=null://null');
$_SERVER['MAILER_DSN'] = $_ENV['MAILER_DSN'] = 'null://null';
putenv('LOCK_DSN=flock');
$_SERVER['LOCK_DSN'] = $_ENV['LOCK_DSN'] = 'flock';
// DATABASE_URL comes from the caller (the CI lane points it at crm_ci_test).

$kernel = new App\Kernel('test', false);
$kernel->boot();

/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();

$email = 'demo.admin@starz.local';
$existing = $em->getRepository(User::class)->findOneBy(['email' => $email]);

if ($existing !== null && $existing->isActive()) {
    echo "demo user already present (#{$existing->getId()})\n";
    exit(0);
}

$user = $existing ?? new User();
$user->setEmail($email);
$user->setFirstName('Demo');
$user->setLastName('Admin');
// Bcrypt via password_hash — Symfony's hasher verifies these directly.
$user->setPassword(password_hash('DemoPass2026', PASSWORD_BCRYPT));
$user->setRoles(['ROLE_ADMIN']);
$user->setActive(true);

$em->persist($user);
$em->flush();

echo "demo user seeded (#{$user->getId()})\n";
