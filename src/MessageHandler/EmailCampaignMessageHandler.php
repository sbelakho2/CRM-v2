<?php

namespace App\MessageHandler;

use App\Message\EmailCampaignMessage;
use App\Repository\EmailCampaignRepository;
use App\Repository\ContactRepository;
use App\Service\EmailCampaignService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
class EmailCampaignMessageHandler
{
    private const MAX_ATTEMPTS_CAP = 3;

    public function __construct(
        private EmailCampaignRepository $campaignRepository,
        private ContactRepository $contactRepository,
        private EmailCampaignService $campaignService,
        private ?MessageBusInterface $messageBus = null,
        private int $retryLimit = 3
    ) {}

    public function __invoke(EmailCampaignMessage $message)
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

        $this->redispatchFailed($message, $failedRecipientIds);
    }

    /**
     * Re-queue only the recipients whose send failed, with the SAME touch
     * number and an incremented retry attempt, so successful recipients are
     * never sent twice and failures never advance the drip sequence. When the
     * attempt limit is reached the failure is already persisted on the
     * EmailSend record (status/failureReason set by EmailCampaignService).
     */
    private function redispatchFailed(EmailCampaignMessage $message, array $failedRecipientIds): void
    {
        if (empty($failedRecipientIds) || $this->messageBus === null) {
            return;
        }

        $attempt = $message->getRetryAttempt();
        if ($attempt >= $this->retryLimit || $attempt >= self::MAX_ATTEMPTS_CAP) {
            return;
        }

        $this->messageBus->dispatch(new EmailCampaignMessage(
            $message->getCampaignId(),
            $failedRecipientIds,
            $message->getTouchNumber(),
            $attempt + 1
        ));
    }
}
