<?php

namespace App\Tests\Unit\MessageHandler;

use App\Message\EmailCampaignMessage;
use App\MessageHandler\EmailCampaignMessageHandler;
use App\Entity\EmailCampaign;
use App\Entity\Contact;
use PHPUnit\Framework\TestCase;

class EmailCampaignMessageHandlerTest extends TestCase
{
    public function testInvokeThrowsWhenCampaignNotFound(): void
    {
        $campaignRepo = $this->createMock(\App\Repository\EmailCampaignRepository::class);
        $contactRepo = $this->createMock(\App\Repository\ContactRepository::class);
        $service = $this->createMock(\App\Service\EmailCampaignService::class);

        $campaignRepo->expects($this->once())->method('find')->with(99)->willReturn(null);

        $handler = new EmailCampaignMessageHandler($campaignRepo, $contactRepo, $service);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Campaign not found');

        $handler(new EmailCampaignMessage(99, [10]));
    }

    public function testInvokeSendsToEachRecipient(): void
    {
        $campaign = new EmailCampaign();
        $campaign->setName('Unit Test Campaign');

        $recipient1 = new Contact();
        $recipient1->setFirstName('Alice');
        $recipient2 = new Contact();
        $recipient2->setFirstName('Bob');

        $campaignRepo = $this->createMock(\App\Repository\EmailCampaignRepository::class);
        $contactRepo = $this->createMock(\App\Repository\ContactRepository::class);
        $service = $this->createMock(\App\Service\EmailCampaignService::class);

        $campaignRepo->method('find')->willReturn($campaign);
        $contactRepo->method('findBy')->with(['id' => [5,6]])->willReturn([$recipient1, $recipient2]);

        // Expect sendToContact called twice with the campaign and each recipient
        $service->expects($this->exactly(2))
            ->method('sendToContact')
            ->withConsecutive(
                [$this->identicalTo($campaign), $this->identicalTo($recipient1), 1],
                [$this->identicalTo($campaign), $this->identicalTo($recipient2), 1]
            )
            ->willReturn(\App\Service\CampaignSendResult::sent());

        $handler = new EmailCampaignMessageHandler($campaignRepo, $contactRepo, $service);

        $handler(new EmailCampaignMessage($campaign->getId() ?? 1, [5,6]));
    }
}
