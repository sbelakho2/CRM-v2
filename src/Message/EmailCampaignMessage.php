<?php

namespace App\Message;

class EmailCampaignMessage
{
    private int $campaignId;
    private array $recipientIds;

    public function __construct(int $campaignId, array $recipientIds)
    {
        $this->campaignId = $campaignId;
        $this->recipientIds = $recipientIds;
    }

    public function getCampaignId(): int
    {
        return $this->campaignId;
    }

    public function getRecipientIds(): array
    {
        return $this->recipientIds;
    }
}