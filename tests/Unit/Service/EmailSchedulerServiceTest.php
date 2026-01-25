<?php

namespace App\Tests\Unit\Service;

use App\Entity\Company;
use App\Entity\Contact;
use App\Entity\EmailCampaign;
use App\Entity\EmailUnsubscribe;
use App\Repository\EmailUnsubscribeRepository;
use App\Service\EmailCampaignService;
use App\Service\EmailSchedulerService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\MessageBusInterface;

class EmailSchedulerServiceTest extends TestCase
{
    private function createService(
        ?EntityManagerInterface $em = null,
        ?MessageBusInterface $messageBus = null,
        ?EmailCampaignService $campaignService = null
    ): EmailSchedulerService {
        return new EmailSchedulerService(
            $em ?? $this->createMock(EntityManagerInterface::class),
            $messageBus ?? $this->createMock(MessageBusInterface::class),
            $campaignService ?? $this->createMock(EmailCampaignService::class)
        );
    }

    public function testScheduleCampaignSetsScheduledAt(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $service = $this->createService($em);

        $campaign = new EmailCampaign();
        $scheduled = new \DateTimeImmutable('2030-01-01 10:00:00');

        $service->scheduleCampaign($campaign, $scheduled, false);

        $this->assertEquals($scheduled, $campaign->getScheduledAt());
    }

    public function testScheduleCampaignReturnsZero(): void
    {
        $service = $this->createService();
        $campaign = new EmailCampaign();

        $result = $service->scheduleCampaign($campaign, null, false);

        $this->assertSame(0, $result);
    }

    public function testScheduleCampaignWithOptimizeSendTime(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $service = $this->createService($em);
        $campaign = new EmailCampaign();

        // optimizeSendTime is a noop currently but should not error
        $result = $service->scheduleCampaign($campaign, null, true);

        $this->assertSame(0, $result);
    }

    public function testProcessCampaignWithNoContactsReturnsZero(): void
    {
        $campaignService = $this->createMock(EmailCampaignService::class);
        $campaignService->expects($this->never())->method('sendToContact');

        $service = $this->createService(null, null, $campaignService);

        $campaign = new EmailCampaign();
        // No contacts added

        $count = $service->processCampaign($campaign);

        $this->assertSame(0, $count);
    }

    public function testProcessCampaignSendsToContactsWithEmail(): void
    {
        $unsubRepo = $this->createMock(EmailUnsubscribeRepository::class);
        $unsubRepo->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')
            ->with(EmailUnsubscribe::class)
            ->willReturn($unsubRepo);

        $campaignService = $this->createMock(EmailCampaignService::class);
        $campaignService->expects($this->exactly(2))->method('sendToContact');

        $service = $this->createService($em, null, $campaignService);

        $campaign = new EmailCampaign();

        $company = new Company();
        $company->setName('Test Co');

        $contact1 = new Contact();
        $contact1->setFirstName('Alice');
        $contact1->setLastName('A');
        $contact1->setEmail('alice@example.com');
        $contact1->setCompany($company);

        $contact2 = new Contact();
        $contact2->setFirstName('Bob');
        $contact2->setLastName('B');
        $contact2->setEmail('bob@example.com');
        $contact2->setCompany($company);

        $campaign->addContact($contact1);
        $campaign->addContact($contact2);

        $count = $service->processCampaign($campaign);

        $this->assertSame(2, $count);
    }

    public function testProcessCampaignSkipsContactsWithoutEmail(): void
    {
        $unsubRepo = $this->createMock(EmailUnsubscribeRepository::class);
        $unsubRepo->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')
            ->with(EmailUnsubscribe::class)
            ->willReturn($unsubRepo);

        $campaignService = $this->createMock(EmailCampaignService::class);
        $campaignService->expects($this->once())->method('sendToContact');

        $service = $this->createService($em, null, $campaignService);

        $campaign = new EmailCampaign();

        $company = new Company();
        $company->setName('Test Co');

        $contactWithEmail = new Contact();
        $contactWithEmail->setFirstName('Alice');
        $contactWithEmail->setLastName('A');
        $contactWithEmail->setEmail('alice@example.com');
        $contactWithEmail->setCompany($company);

        $contactWithoutEmail = new Contact();
        $contactWithoutEmail->setFirstName('NoEmail');
        $contactWithoutEmail->setLastName('Person');
        $contactWithoutEmail->setCompany($company);
        // No email set

        $campaign->addContact($contactWithEmail);
        $campaign->addContact($contactWithoutEmail);

        $count = $service->processCampaign($campaign);

        $this->assertSame(1, $count);
    }

    public function testProcessCampaignSkipsUnsubscribedContacts(): void
    {
        $unsubscribed = new EmailUnsubscribe();

        $unsubRepo = $this->createMock(EmailUnsubscribeRepository::class);
        $unsubRepo->method('findOneBy')->willReturnCallback(function (array $criteria) use ($unsubscribed) {
            // unsubscribed@example.com is on the suppression list
            if ($criteria['email'] === 'unsubscribed@example.com') {
                return $unsubscribed;
            }
            return null;
        });

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')
            ->with(EmailUnsubscribe::class)
            ->willReturn($unsubRepo);

        $campaignService = $this->createMock(EmailCampaignService::class);
        $campaignService->expects($this->once())->method('sendToContact');

        $service = $this->createService($em, null, $campaignService);

        $campaign = new EmailCampaign();

        $company = new Company();
        $company->setName('Test Co');

        $activeContact = new Contact();
        $activeContact->setFirstName('Active');
        $activeContact->setLastName('Person');
        $activeContact->setEmail('active@example.com');
        $activeContact->setCompany($company);

        $unsubContact = new Contact();
        $unsubContact->setFirstName('Unsub');
        $unsubContact->setLastName('Person');
        $unsubContact->setEmail('unsubscribed@example.com');
        $unsubContact->setCompany($company);

        $campaign->addContact($activeContact);
        $campaign->addContact($unsubContact);

        $count = $service->processCampaign($campaign);

        $this->assertSame(1, $count);
    }
}
