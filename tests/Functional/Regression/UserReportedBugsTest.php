<?php

namespace App\Tests\Functional\Regression;

use App\Entity\Company;
use App\Entity\Contact;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Regression tests for user-reported defects:
 *
 * 1. Creating a company whose website URL is longer than 255 characters
 *    used to throw a DataTooLongException (HTTP 500) on save. The columns
 *    were widened to VARCHAR(2048) and the form gained matching length
 *    constraints, so long URLs must save cleanly and over-long URLs must
 *    produce a validation error instead of a 500.
 *
 * 2. "Stay signed in" (remember me) never issued a REMEMBERME cookie,
 *    because the custom authenticator did not add a RememberMeBadge to the
 *    passport. Logging in with _remember_me checked must set the cookie;
 *    logging in without it must not.
 */
class UserReportedBugsTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['users', 'companies', 'contacts'] as $table) {
            $connection->executeStatement('TRUNCATE TABLE ' . $table);
        }
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    // ─────────────────────────────────────────────────────────────
    // Bug 1: long company website URL must not 500
    // ─────────────────────────────────────────────────────────────

    public function testCompanyWithLongWebsiteUrlPersistsWithoutError(): void
    {
        $longUrl = 'https://www.example.com/' . str_repeat('segment-', 30) . 'end';

        $company = new Company();
        $company->setName('Long URL Co');
        $company->setAccountTier(Company::TIER_C);
        $company->setSector('Industrial');
        $company->setWebsite($longUrl);
        $company->setLinkedInUrl('https://www.linkedin.com/' . str_repeat('company/', 25) . 'x');

        $this->entityManager->persist($company);
        $this->entityManager->flush();

        $this->assertGreaterThan(255, strlen($longUrl));

        $this->entityManager->clear();
        $saved = $this->entityManager->getRepository(Company::class)->find($company->getId());
        $this->assertNotNull($saved);
        $this->assertSame($longUrl, $saved->getWebsite());
        $this->assertSame('https://www.linkedin.com/' . str_repeat('company/', 25) . 'x', $saved->getLinkedInUrl());
    }

    public function testCompanyWithLongWebsiteUrlDoesNot500ViaForm(): void
    {
        $this->createUser('form@example.com');
        $this->client->loginUser($this->loadUser('form@example.com'));

        $longUrl = 'https://www.example.org/' . str_repeat('x', 300);

        $crawler = $this->client->request('GET', '/companies/new');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Create')->form();
        $form['company[name]'] = 'Long URL Form Co';
        $form['company[sector]'] = 'Industrial';
        $form['company[website]'] = $longUrl;
        $this->client->submit($form);

        $this->assertResponseRedirects();
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();

        $company = $this->entityManager->getRepository(Company::class)->findOneBy(['name' => 'Long URL Form Co']);
        $this->assertNotNull($company);
        $this->assertSame($longUrl, $company->getWebsite());
    }

    public function testCompanyWithOverlongWebsiteUrlShowsValidationErrorNot500(): void
    {
        $this->createUser('form2@example.com');
        $this->client->loginUser($this->loadUser('form2@example.com'));

        $overlongUrl = 'https://www.example.net/' . str_repeat('y', 2100);

        $crawler = $this->client->request('GET', '/companies/new');
        $this->assertResponseIsSuccessful();

        $form = $crawler->selectButton('Create')->form();
        $form['company[name]'] = 'Overlong URL Co';
        $form['company[sector]'] = 'Industrial';
        $form['company[website]'] = $overlongUrl;
        $this->client->submit($form);

        // Symfony 7.4 answers invalid form submissions with 200 or 422
        // (Unprocessable Content) — never 500, and never a redirect.
        $status = $this->client->getResponse()->getStatusCode();
        $this->assertContains($status, [200, 422], 'Over-long URL must produce a validation response, not a 500/redirect');
        $this->assertStringContainsString('too long', strtolower($this->client->getResponse()->getContent()));
        $this->assertNull($this->entityManager->getRepository(Company::class)->findOneBy(['name' => 'Overlong URL Co']));
    }

    // ─────────────────────────────────────────────────────────────
    // Bug 2: "Stay signed in" must issue the REMEMBERME cookie
    // ─────────────────────────────────────────────────────────────

    public function testLoginWithRememberMeSetsRememberMeCookie(): void
    {
        $this->createUser('remember@example.com');

        $crawler = $this->client->request('GET', '/login');
        $this->assertResponseIsSuccessful();

        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');

        $this->client->request('POST', '/login', [
            '_username' => 'remember@example.com',
            '_password' => 'test-password',
            '_remember_me' => 'on',
            '_csrf_token' => $token,
        ]);

        $this->assertResponseRedirects();

        $cookieHeader = $this->client->getResponse()->headers->get('Set-Cookie', '');
        $this->assertStringContainsString('REMEMBERME=', $cookieHeader, 'REMEMBERME cookie must be set when "stay signed in" is checked');
    }

    public function testLoginWithoutRememberMeDoesNotSetRememberMeCookie(): void
    {
        $this->createUser('noremember@example.com');

        $crawler = $this->client->request('GET', '/login');
        $this->assertResponseIsSuccessful();

        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');

        $this->client->request('POST', '/login', [
            '_username' => 'noremember@example.com',
            '_password' => 'test-password',
            '_csrf_token' => $token,
        ]);

        $this->assertResponseRedirects();

        $cookieHeader = $this->client->getResponse()->headers->get('Set-Cookie', '');
        $this->assertStringNotContainsString('REMEMBERME=', $cookieHeader, 'No REMEMBERME cookie may be set without the checkbox');
    }

    public function testRememberMeCookieAuthenticatesSubsequentRequest(): void
    {
        $this->createUser('persist@example.com');

        $crawler = $this->client->request('GET', '/login');
        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');

        $this->client->request('POST', '/login', [
            '_username' => 'persist@example.com',
            '_password' => 'test-password',
            '_remember_me' => 'on',
            '_csrf_token' => $token,
        ]);

        // Simulate a browser restart: drop every cookie except REMEMBERME
        // (the session cookie dies with the browser; the remember-me cookie
        // must survive and re-authenticate the user).
        $rememberMeCookie = $this->client->getCookieJar()->get('REMEMBERME');
        $this->assertNotNull($rememberMeCookie, 'REMEMBERME cookie must exist after login');
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('REMEMBERME', $rememberMeCookie->getValue()));

        $this->client->request('GET', '/command-center');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Command Center', $this->client->getResponse()->getContent());
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    private function createUser(string $email): void
    {
        $user = new User();
        $user->setEmail($email);
        $user->setRoles(['ROLE_USER']);
        $user->setFirstName('Regression');
        $user->setLastName('User');
        $user->setIsVerified(true);
        $user->setPassword(self::getContainer()->get('security.password_hasher')->hashPassword($user, 'test-password'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }

    private function loadUser(string $email): UserInterface
    {
        $provider = self::getContainer()->get(UserProviderInterface::class);

        return $provider->loadUserByIdentifier($email);
    }
}
