<?php

namespace App\Tests\Functional\Regression;

use App\Entity\Activity;
use App\Entity\Company;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Regression tests for the activity-display defects:
 *
 * 1. Company pages showed an arbitrary slice of a company's activities: the
 *    join query that hydrates the activities collection had no ORDER BY, so
 *    the SQL engine chose the row order and "recent activity" panels cut the
 *    collection in that order — recently-added activities vanished from the
 *    company page once a company had more than five.
 *
 * 2. The activities index paginated at 50 rows per page but rendered no
 *    pagination controls — once a single month filled the first page, every
 *    older activity became unreachable.
 */
class ActivityRegressionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['users', 'companies', 'activities'] as $table) {
            $connection->executeStatement('TRUNCATE TABLE ' . $table);
        }
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testCompanyPageShowsNewestActivitiesFirst(): void
    {
        $user = $this->createUser('act-owner@example.com');
        $company = new Company();
        $company->setName('Activity Order Co');
        $company->setAccountTier(Company::TIER_C);
        $company->setSector('Industrial');
        $company->setPipelineStage(Company::STAGE_PROSPECT);
        $this->entityManager->persist($company);
        $this->entityManager->flush();

        // Six activities, oldest first — dates well separated so ordering is
        // unambiguous.
        $dates = [
            '2026-07-01 10:00:00' => 'OLDEST',
            '2026-07-10 10:00:00' => 'OLD-2',
            '2026-08-01 10:00:00' => 'OLD-3',
            '2026-08-20 10:00:00' => 'MID',
            '2026-08-30 10:00:00' => 'NEW-2',
            '2026-09-01 09:00:00' => 'NEWEST',
        ];
        foreach ($dates as $date => $note) {
            $activity = new Activity();
            $activity->setType('Call');
            $activity->setNotes($note);
            $activity->setActivityDate(new \DateTime($date));
            $activity->setCompany($company);
            $activity->setUser($user);
            $this->entityManager->persist($activity);
        }
        $this->entityManager->flush();

        // Mirror production: each HTTP request runs against a fresh entity
        // manager. The shared test EM keeps Company managed, and Doctrine's
        // joined-collection hydration skips collections on managed roots.
        $this->entityManager->clear();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/companies/' . $company->getId());
        $this->assertResponseIsSuccessful();

        $panelText = $crawler->filter('.rams-module')->reduce(function ($node) {
            return str_contains($node->text(), 'Recent Activity');
        })->text();

        // Newest five must appear, in descending date order.
        $this->assertStringContainsString('NEWEST', $panelText);
        $this->assertStringContainsString('NEW-2', $panelText);
        $this->assertStringContainsString('MID', $panelText);
        $this->assertStringContainsString('OLD-3', $panelText);
        $this->assertStringContainsString('OLD-2', $panelText);
        // The oldest must be cut by the five-item slice.
        $this->assertStringNotContainsString('OLDEST', $panelText);

        $newestPosition = strpos($panelText, 'NEWEST');
        $midPosition = strpos($panelText, 'MID');
        $oldPosition = strpos($panelText, 'OLD-3');
        $this->assertNotFalse($newestPosition);
        $this->assertNotFalse($midPosition);
        $this->assertNotFalse($oldPosition);
        $this->assertLessThan($midPosition, $newestPosition, 'Newest activity must render first');
        $this->assertLessThan($oldPosition, $midPosition, 'Activities must render newest-first');
    }

    public function testActivitiesPagePaginatesOldHistory(): void
    {
        $user = $this->createUser('act-page-owner@example.com');
        $company = new Company();
        $company->setName('Paginated Co');
        $company->setAccountTier(Company::TIER_C);
        $company->setSector('Industrial');
        $company->setPipelineStage(Company::STAGE_PROSPECT);
        $this->entityManager->persist($company);
        $this->entityManager->flush();

        // 55 activities: the first page (50) fills with recent ones, the
        // remaining 5 (the oldest) must be reachable on page 2.
        for ($i = 1; $i <= 55; $i++) {
            $activity = new Activity();
            $activity->setType('Call');
            $activity->setNotes('Bulk-' . $i);
            $activity->setActivityDate(new \DateTime(sprintf('2026-07-%02d 10:00:00', min($i, 31))));
            $activity->setCompany($company);
            $activity->setUser($user);
            $this->entityManager->persist($activity);
        }
        $this->entityManager->flush();

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/activities');
        $this->assertResponseIsSuccessful();
        $this->assertCount(50, $crawler->filter('tbody tr'));
        $this->assertStringContainsString('Page 1 of 2', $crawler->text());

        $nextLink = $crawler->filter('a[href*="page=2"]');
        $this->assertCount(1, $nextLink, 'Next-page link must exist');

        $page2 = $this->client->request('GET', '/activities?page=2');
        $this->assertResponseIsSuccessful();
        $this->assertCount(5, $page2->filter('tbody tr'), 'Oldest activities must be reachable on page 2');
    }

    private function createUser(string $email): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setRoles(['ROLE_USER']);
        $user->setFirstName('Activity');
        $user->setLastName('Tester');
        $user->setIsVerified(true);
        $user->setPassword(self::getContainer()->get('security.password_hasher')->hashPassword($user, 'test-password'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}
