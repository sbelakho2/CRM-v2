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
        private EmailCampaignService $campaignService,
    ) {}

    public function __invoke(EmailCampaignMessage $message): void
    {
        $campaign = $this->campaignRepository->find($message->getCampaignId());
        if (!$campaign) {
            throw new \RuntimeException('Campaign not found');
        }

        // The touch number is the semantic identity of this send. The retry
        // attempt is infrastructure-only and must never leak into it: retrying
        // a failed delivery of touch 1 is still touch 1, otherwise a transport
        // hiccup would silently advance the customer's drip sequence.
        $touchNumber = $message->getTouchNumber();
        $recipients = $this->contactRepository->findBy(['id' => $message->getRecipientIds()]);

        $failedRecipientIds = [];
        foreach ($recipients as $recipient) {
            // FAILED is the only outcome warranting a retry; policy-skipped
            // and idempotent replays are terminal for this attempt.
            $result = $this->campaignService->sendToContact($campaign, $recipient, $touchNumber);

            if ($result->outcome === \App\Service\CampaignSendResult::FAILED) {
                $failedRecipientIds[] = $recipient->getId();
            }
        }

        // NO handler-level redispatch: transport retries are owned SOLELY by
        // the database backoff worker (app:email:process-due-sends) — failed
        // rows carry next_attempt_at + attempt counters there. A second,
        // competing retry system here created duplicate messages and states.
    }
}
