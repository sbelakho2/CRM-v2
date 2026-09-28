<?php

namespace App\Tests\Functional\Regression;

use App\Entity\Company;
use App\Entity\Contact;
use App\Entity\EmailCampaign;
use App\Entity\EmailSend;
use App\Repository\EmailSendRepository;
use App\Service\CampaignSendResult;
use App\Service\EmailCampaignService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Regression tests for campaign retry/idempotency semantics.
 *
 * History: the first idempotency implementation made failed sends
 * un-retryable — a FAILED row was invisible to the pre-check, the retry's
 * INSERT violated the unique (campaign, contact, touch) constraint, and the
 * violation was reported as SUCCESS. A failed delivery could therefore
 * never be retried, silently.
 */
class EmailRetrySemanticsTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $connection = $this->em->getConnection();
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['email_sends', 'email_campaigns', 'email_unsubscribe', 'contacts', 'companies'] as $table) {
            $connection->executeStatement('TRUNCATE TABLE ' . $table);
        }
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testFailedTouchIsRetriedOnTheSameRow(): void
    {
        [$campaign, $contact] = $this->fixtures();
        $service = static::getContainer()->get(EmailCampaignService::class);

        $failed = new EmailSend();
        $failed->setCampaign($campaign);
        $failed->setContact($contact);
        $failed->setTouchNumber(1);
        $failed->setEmailAddress($contact->getEmail());
        $failed->setStatus(EmailSend::STATUS_FAILED);
        $failed->setFailureReason('smtp refused');
        $this->em->persist($failed);
        $this->em->flush();
        $failedId = $failed->getId();
        $this->em->clear();

        // Retry with the null transport (test env): must SUCCEED by reusing
        // the existing FAILED row — never inserting a second row.
        $result = $service->sendToContact(
            $this->em->find(EmailCampaign::class, $campaign->getId()),
            $this->em->find(Contact::class, $contact->getId()),
            1
        );

        $this->assertSame(CampaignSendResult::SENT, $result->outcome);

        $rows = $this->em->getRepository(EmailSend::class)->findAll();
        $this->assertCount(1, $rows, 'Retry must reuse the failed row, not create a second one');
        $this->assertSame($failedId, $rows[0]->getId());
        $this->assertSame(EmailSend::STATUS_SENT, $rows[0]->getStatus());
        $this->assertSame(1, $rows[0]->getRetryCount(), 'Retry must increment retryCount on the reused row');
        $this->assertNull($rows[0]->getFailureReason());
    }

    public function testSentTouchIsNeverResent(): void
    {
        [$campaign, $contact] = $this->fixtures();
        $service = static::getContainer()->get(EmailCampaignService::class);

        $sent = new EmailSend();
        $sent->setCampaign($campaign);
        $sent->setContact($contact);
        $sent->setTouchNumber(1);
        $sent->setEmailAddress($contact->getEmail());
        $sent->setStatus(EmailSend::STATUS_SENT);
        $sent->setSentAt(new \DateTime());
        $this->em->persist($sent);
        $this->em->flush();
        $sentAt = $sent->getSentAt();
        $this->em->clear();

        $result = $service->sendToContact(
            $this->em->find(EmailCampaign::class, $campaign->getId()),
            $this->em->find(Contact::class, $contact->getId()),
            1
        );

        $this->assertSame(CampaignSendResult::ALREADY_SENT, $result->outcome);

        $row = $this->em->getRepository(EmailSend::class)->findAll()[0];
        $this->assertSame($sentAt->getTimestamp(), $row->getSentAt()->getTimestamp(), 'A delivered touch must never be re-sent or re-stamped');
    }

    public function testUnsubscribedContactIsSkippedOnEveryPath(): void
    {
        [$campaign, $contact] = $this->fixtures();
        $service = static::getContainer()->get(EmailCampaignService::class);

        // Globally unsubscribed: the canonical send path must refuse.
        $this->em->getConnection()->executeStatement(
            'INSERT INTO email_unsubscribe (email, unsubscribed_at) VALUES (?, NOW())',
            [$contact->getEmail()]
        );

        $result = $service->sendToContact($campaign, $contact, 1);

        $this->assertSame('skipped', $result->outcome);
        $this->assertSame('unsubscribed', $result->skipReason);
        $this->assertCount(0, $this->em->getRepository(EmailSend::class)->findAll(), 'A skipped send must not leave an EmailSend row');
    }

    public function testScheduledCampaignIsDispatchedExactlyOnce(): void
    {
        [$campaign] = $this->fixtures();
        $campaign->setActive(true);
        $campaign->setScheduledAt((new \DateTime())->modify('-5 minutes'));
        $this->em->flush();

        $scheduler = static::getContainer()->get(\App\Service\EmailSchedulerService::class);

        $first = $scheduler->dispatchDueCampaigns();
        $this->assertCount(1, $first, 'A due campaign is dispatched');

        $this->em->clear();
        $claimed = $this->em->find(EmailCampaign::class, $campaign->getId());
        $this->assertNotNull($claimed->getScheduledDispatchedAt(), 'Dispatch marks the campaign as claimed');
        $this->assertNotNull($claimed->getScheduledAt(), 'Original schedule is preserved for audit');

        // Overlapping cron invocation: nothing re-dispatched.
        $second = $scheduler->dispatchDueCampaigns();
        $this->assertSame([], $second, 'An already-dispatched campaign must never be enqueued again');
    }

    public function testNextTouchSelectionRequiresSentPreviousTouch(): void
    {
        [$campaign, $contact] = $this->fixtures();
        $campaign->addContact($contact);
        $this->em->flush();

        $service = static::getContainer()->get(EmailCampaignService::class);

        // Touch 1: enrolled contact with no touch-1 record is eligible.
        $eligible = $service->getContactsForNextTouch($campaign, 1);
        $this->assertCount(1, $eligible);

        // Touch 2 with no sent touch 1: nobody is eligible.
        $this->assertSame([], $service->getContactsForNextTouch($campaign, 2));

        // After touch 1 is SENT, touch 2 becomes eligible.
        $sent = new EmailSend();
        $sent->setCampaign($campaign);
        $sent->setContact($contact);
        $sent->setTouchNumber(1);
        $sent->setEmailAddress($contact->getEmail());
        $sent->setStatus(EmailSend::STATUS_SENT);
        $sent->setSentAt(new \DateTime());
        $this->em->persist($sent);
        $this->em->flush();
        $this->em->clear();

        $eligible2 = $service->getContactsForNextTouch(
            $this->em->find(EmailCampaign::class, $campaign->getId()),
            2
        );
        $this->assertCount(1, $eligible2, 'SENT touch 1 unlocks touch 2');
    }

    /**
     * @return array{0: EmailCampaign, 1: Contact}
     */
    private function fixtures(): array
    {
        $company = new Company();
        $company->setName('Retry Semantics Co');
        $company->setAccountTier(Company::TIER_C);
        $company->setSector('Industrial');
        $this->em->persist($company);

        $contact = new Contact();
        $contact->setFirstName('Retry');
        $contact->setLastName('Tester');
        $contact->setEmail('retry.tester@example.com');
        $contact->setCompany($company);
        $this->em->persist($contact);

        $campaign = new EmailCampaign();
        $campaign->setName('Retry Semantics Campaign');
        $campaign->setLanguage('EN');
        $campaign->setActive(true);
        $this->em->persist($campaign);

        $this->em->flush();

        return [$campaign, $contact];
    }
}
