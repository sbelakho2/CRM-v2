<?php

namespace App\Message;

class EmailCampaignMessage
{
    private int $campaignId;
    private array $recipientIds;
    private int $attempt;

    public function __construct(int $campaignId, array $recipientIds, int $attempt = 1)
    {
        $this->campaignId = $campaignId;
        $this->recipientIds = $recipientIds;
        $this->attempt = $attempt;
    }

    public function getCampaignId(): int
    {
        return $this->campaignId;
    }

    public function getRecipientIds(): array
    {
        return $this->recipientIds;
    }

    public function getAttempt(): int
    {
        return $this->attempt;
    }
}
