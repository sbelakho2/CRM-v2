<?php

namespace App\Tests\Functional\Regression;

use App\Entity\Contact;
use App\Entity\EmailCampaign;
use App\Entity\EmailSend;
use App\Entity\Webinar;
use App\Repository\WebinarAttendeeRepository;
use App\Service\CampaignSendResult;
use App\Service\EmailCampaignService;
use App\Service\EmailSendPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Round-3 regressions: archived campaigns must be non-sendable, touch
 * numbers validated, webinar filters must keep hiding archived rows, and
 * public webinar registration must work end-to-end with CSRF.
 */
class Round3HardeningTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $connection = $this->em->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['webinar_attendees', 'webinars', 'email_sends', 'email_campaigns', 'contacts', 'companies'] as $table) {
            $connection->executeStatement('TRUNCATE TABLE ' . $table);
        }
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testArchivedCampaignIsRefusedByPolicyAndCannotSend(): void
    {
        [$campaign, $contact] = $this->campaignFixtures();

        $archiver = new \App\Entity\User();
        $archiver->setEmail('archiver-r3@example.com');
        $archiver->setPassword('x');
        $archiver->setRoles(['ROLE_USER']);
        $this->em->persist($archiver);

        $campaign->archive($archiver, 'test archive');

        // The entity transition itself deactivates and cancels.
        $this->assertFalse($campaign->isActive());
        $this->assertSame(EmailCampaign::STATUS_CANCELLED, $campaign->getStatus());

        // The canonical policy refuses archived campaigns on every path.
        $policy = static::getContainer()->get(EmailSendPolicy::class);
        $eligibility = $policy->evaluate($contact, $campaign);
        $this->assertFalse($eligibility->allowed);
        $this->assertSame('campaign_archived', $eligibility->reason);

        // And sendToContact reports the skip without creating a row.
        $service = static::getContainer()->get(EmailCampaignService::class);
        $result = $service->sendToContact($campaign, $contact, 1);
        $this->assertSame('skipped', $result->outcome);
        $this->assertSame('campaign_archived', $result->skipReason);
        $this->assertCount(0, $this->em->getRepository(EmailSend::class)->findAll());
    }

    public function testTouchNumbersOutsideTheSequenceAreRejected(): void
    {
        [$campaign, $contact] = $this->campaignFixtures();
        $campaign->setTouchCount(3);
        $this->em->flush();

        $service = static::getContainer()->get(EmailCampaignService::class);

        foreach ([0, -1, 4, 999] as $invalid) {
            try {
                $service->sendToContact($campaign, $contact, $invalid);
                $this->fail(sprintf('Touch %d must be rejected', $invalid));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('outside the campaign sequence', $e->getMessage());
            }
        }
    }

    public function testArchivedWebinarStaysHiddenUnderEveryListFilter(): void
    {
        $live = new Webinar();
        $live->setTitle('Live Webinar');
        $live->setScheduledDate((new \DateTime())->modify('+2 days'));
        $this->em->persist($live);

        $archived = new Webinar();
        $archived->setTitle('Archived Webinar');
        $archived->setScheduledDate((new \DateTime())->modify('+2 days'));
        $archived->archive($this->user(), 'test');
        $this->em->persist($archived);
        $this->em->flush();

        // The webinar list is ROLE_USER-protected.
        $viewer = $this->user();
        $viewer->setEmail('viewer-r3@example.com');
        $this->em->flush();
        $this->em->clear();
        $this->client->loginUser(
            static::getContainer()->get('doctrine')->getRepository(\App\Entity\User::class)->find($viewer->getId())
        );

        $crawlerAll = $this->client->request('GET', '/webinars');
        $crawlerUpcoming = $this->client->request('GET', '/webinars?status=upcoming');
        $crawlerPast = $this->client->request('GET', '/webinars?status=past');

        // where() previously REPLACED the archive predicate under filters.
        foreach (['' => $crawlerAll, '?status=upcoming' => $crawlerUpcoming, '?status=past' => $crawlerPast] as $crawler) {
            $titles = $crawler->filter('body')->text();
            $this->assertStringNotContainsString('Archived Webinar', $titles);
        }
        $this->assertStringContainsString('Live Webinar', $crawlerUpcoming->filter('body')->text());
    }

    public function testPublicWebinarRegistrationFlowWithCsrf(): void
    {
        $webinar = new Webinar();
        $webinar->setTitle('Public Flow Webinar');
        $webinar->setScheduledDate((new \DateTime())->modify('+3 days'));
        $this->em->persist($webinar);
        $this->em->flush();

        // Anonymous GET succeeds (public route, no login redirect).
        $crawler = $this->client->request('GET', '/webinars/' . $webinar->getId() . '/register');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('_csrf_token', $this->client->getResponse()->getContent());

        $token = $crawler->filter('input[name=_csrf_token]')->attr('value');

        // Invalid CSRF → 403.
        $this->client->request('POST', '/webinars/' . $webinar->getId() . '/register', [
            '_csrf_token' => 'forged',
            'email' => 'external@example.com',
            'first_name' => 'Ex',
            'last_name' => 'Ternal',
        ]);
        $this->assertResponseStatusCodeSame(403);

        // Valid CSRF → confirmation redirect; attendee persisted WITHOUT a
        // CRM contact (external registrants are attendee-only).
        $this->client->request('POST', '/webinars/' . $webinar->getId() . '/register', [
            '_csrf_token' => $token,
            'email' => 'external@example.com',
            'first_name' => 'Ex',
            'last_name' => 'Ternal',
            'company' => 'External Co',
        ]);
        $this->assertResponseRedirects();

        $attendee = static::getContainer()->get(WebinarAttendeeRepository::class)
            ->findOneBy(['webinar' => $webinar, 'email' => 'external@example.com']);
        $this->assertNotNull($attendee, 'Registration must persist the attendee');
        $this->assertNull($attendee->getContact(), 'External registrants must not be forced into the Contact model');
        $this->assertSame('External Co', $attendee->getCompanyName());
    }

    public function testStaleSendingLeaseIsReclaimable(): void
    {
        [$campaign, $contact] = $this->campaignFixtures();
        $service = static::getContainer()->get(EmailCampaignService::class);

        // Simulate crash debris: a SENDING row whose lease expired long ago.
        $stale = new EmailSend();
        $stale->setCampaign($campaign);
        $stale->setContact($contact);
        $stale->setTouchNumber(1);
        $stale->setEmailAddress($contact->getEmail());
        $stale->setStatus(EmailSend::STATUS_SENDING);
        $stale->setSendLeaseExpiresAt((new \DateTime())->modify('-1 hour'));
        $this->em->persist($stale);
        $this->em->flush();
        $staleId = $stale->getId();
        $this->em->clear();

        $result = $service->sendToContact(
            $this->em->find(EmailCampaign::class, $campaign->getId()),
            $this->em->find(Contact::class, $contact->getId()),
            1
        );

        $this->assertSame(CampaignSendResult::SENT, $result->outcome);
        $row = $this->em->getRepository(EmailSend::class)->find($staleId);
        $this->assertSame(EmailSend::STATUS_SENT, $row->getStatus());
        $this->assertNull($row->getSendLeaseExpiresAt(), 'A delivered row must release its lease');
    }

    public function testLiveSendingLeaseBlocksConcurrentClaim(): void
    {
        [$campaign, $contact] = $this->campaignFixtures();
        $service = static::getContainer()->get(EmailCampaignService::class);

        $live = new EmailSend();
        $live->setCampaign($campaign);
        $live->setContact($contact);
        $live->setTouchNumber(1);
        $live->setEmailAddress($contact->getEmail());
        $live->setStatus(EmailSend::STATUS_SENDING);
        $live->setSendLeaseExpiresAt((new \DateTime())->modify('+10 minutes'));
        $this->em->persist($live);
        $this->em->flush();
        $this->em->clear();

        $result = $service->sendToContact(
            $this->em->find(EmailCampaign::class, $campaign->getId()),
            $this->em->find(Contact::class, $contact->getId()),
            1
        );

        $this->assertSame(CampaignSendResult::ALREADY_IN_PROGRESS, $result->outcome);
    }

    /**
     * @return array{0: EmailCampaign, 1: Contact}
     */
    private function campaignFixtures(): array
    {
        $company = new \App\Entity\Company();
        $company->setName('R3 Co');
        $company->setAccountTier('C');
        $company->setSector('Industrial');
        $this->em->persist($company);

        $contact = new Contact();
        $contact->setFirstName('R');
        $contact->setLastName('Three');
        $contact->setEmail('r3@example.com');
        $contact->setCompany($company);
        $this->em->persist($contact);

        $campaign = new EmailCampaign();
        $campaign->setName('R3 Campaign');
        $campaign->setLanguage('EN');
        $campaign->setActive(true);
        $this->em->persist($campaign);

        $this->em->flush();

        return [$campaign, $contact];
    }

    private function user(): \App\Entity\User
    {
        $user = new \App\Entity\User();
        $user->setEmail('r3-user@example.com');
        $user->setPassword('x');
        $user->setRoles(['ROLE_USER']);
        $user->setFirstName('R');
        $user->setLastName('Three');
        $this->em->persist($user);

        return $user;
    }
}
