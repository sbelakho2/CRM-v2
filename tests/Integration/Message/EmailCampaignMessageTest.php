<?php

namespace App\Tests\Integration\Message;

use App\Message\EmailCampaignMessage;
use App\MessageHandler\EmailCampaignMessageHandler;
use App\Entity\EmailCampaign;
use App\Entity\Contact;
use App\Repository\EmailCampaignRepository;
use App\Repository\ContactRepository;
use App\Service\EmailCampaignService;
use PHPUnit\Framework\TestCase;

class EmailCampaignMessageTest extends TestCase
{
    private EmailCampaignMessageHandler $handler;
    private EmailCampaignRepository $campaignRepository;
    private ContactRepository $contactRepository;
    private EmailCampaignService $campaignService;

    protected function setUp(): void
    {
        $this->campaignRepository = $this->createMock(EmailCampaignRepository::class);
        $this->contactRepository = $this->createMock(ContactRepository::class);
        $this->campaignService = $this->createMock(EmailCampaignService::class);

        $this->handler = new EmailCampaignMessageHandler(
            $this->campaignRepository,
            $this->contactRepository,
            $this->campaignService
        );
    }

    public function testHandleMessage(): void
    {
        $campaign = new EmailCampaign();
        $contact = new Contact();
        $campaignId = 1;
        $recipientIds = [1];

        $message = new EmailCampaignMessage($campaignId, $recipientIds);

        $this->campaignRepository->expects($this->once())
            ->method('find')
            ->with($campaignId)
            ->willReturn($campaign);

        $this->contactRepository->expects($this->once())
            ->method('findBy')
            ->with(['id' => $recipientIds])
            ->willReturn([$contact]);

        $this->campaignService->expects($this->once())
            ->method('sendToContact')
            ->with($campaign, $contact, 1)
            ->willReturn(\App\Service\CampaignSendResult::sent());

        $this->handler->__invoke($message);
    }

    public function testHandleMessageWithNonExistentCampaign(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Campaign not found');

        $message = new EmailCampaignMessage(999, [1]);

        $this->campaignRepository->expects($this->once())
            ->method('find')
            ->with(999)
            ->willReturn(null);

        $this->handler->__invoke($message);
    }
}