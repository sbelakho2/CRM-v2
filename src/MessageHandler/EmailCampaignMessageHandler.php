<?php

namespace App\MessageHandler;

use App\Message\EmailCampaignMessage;
use App\Repository\EmailCampaignRepository;
use App\Repository\ContactRepository;
use App\Service\EmailCampaignService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class EmailCampaignMessageHandler
{
    public function __construct(
        private EmailCampaignRepository $campaignRepository,
        private ContactRepository $contactRepository,
        private EmailCampaignService $campaignService
    ) {}

    public function __invoke(EmailCampaignMessage $message)
    {
        $campaign = $this->campaignRepository->find($message->getCampaignId());
        if (!$campaign) {
            throw new \RuntimeException('Campaign not found');
        }

        $recipients = $this->contactRepository->findBy(['id' => $message->getRecipientIds()]);
        foreach ($recipients as $recipient) {
            $this->campaignService->sendToContact($campaign, $recipient, 1);
        }
    }
}
