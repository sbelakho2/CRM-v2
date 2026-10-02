<?php

namespace App\Message;

/**
 * Asynchronous campaign send request.
 *
 * Two distinct counters, never to be conflated:
 *
 *  - $touchNumber: the CAMPAIGN touch (1 = first email, 2 = follow-up 1, ...).
 *    Part of the send's identity — (campaign, contact, touch) is unique.
 *
 *  - $retryAttempt: infrastructure retry ordinal AFTER a delivery/worker
 *    failure. It MUST NEVER reach touch-number logic: a retry of touch 1 is
 *    still touch 1. Messenger already tracks its own retry state; this field
 *    only bounds the handler's manual redispatch loop.
 */
class EmailCampaignMessage
{
    public function __construct(
        private int $campaignId,
        /** @var list<int> */
        private array $recipientIds,
        private int $touchNumber = 1,
        private int $retryAttempt = 1,
    ) {}

    public function getCampaignId(): int
    {
        return $this->campaignId;
    }

    /** @return list<int> */
    public function getRecipientIds(): array
    {
        return $this->recipientIds;
    }

    public function getTouchNumber(): int
    {
        return $this->touchNumber;
    }

    public function getRetryAttempt(): int
    {
        return $this->retryAttempt;
    }
}
