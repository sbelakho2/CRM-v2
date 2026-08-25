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

        $attempt = $message->getAttempt();
        $recipients = $this->contactRepository->findBy(['id' => $message->getRecipientIds()]);

        $failedRecipientIds = [];
        foreach ($recipients as $recipient) {
            // sendToContact returns bool: false means the EmailSend record was
            // persisted as failed by the service, so the recipient can be
            // retried.
            $sent = $this->campaignService->sendToContact($campaign, $recipient, $attempt);

            if (!$sent) {
                $failedRecipientIds[] = $recipient->getId();
            }
        }

        $this->redispatchFailed($message, $failedRecipientIds);
    }

    /**
     * Re-queue only the recipients whose send failed, with attempt + 1, so
     * successful recipients are never sent twice. When the attempt limit is
     * reached the failure is already persisted on the EmailSend record
     * (status/failureReason set by EmailCampaignService), so nothing more is
     * done here.
     */
    private function redispatchFailed(EmailCampaignMessage $message, array $failedRecipientIds): void
    {
        if (empty($failedRecipientIds) || $this->messageBus === null) {
            return;
        }

        $attempt = $message->getAttempt();
        if ($attempt >= $this->retryLimit || $attempt >= self::MAX_ATTEMPTS_CAP) {
            return;
        }

        $this->messageBus->dispatch(new EmailCampaignMessage($message->getCampaignId(), $failedRecipientIds, $attempt + 1));
    }
}
