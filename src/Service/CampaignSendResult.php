<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Outcome of EmailCampaignService::sendToContact(). Senders must count
 * outcomes honestly: only SENT means a delivery was attempted and accepted
 * now; ALREADY_* are idempotent replays; SKIPPED_* were policy refusals.
 */
class CampaignSendResult
{
    public const SENT = 'sent';
    public const QUEUED = 'queued';
    public const ALREADY_SENT = 'already_sent';
    public const ALREADY_IN_PROGRESS = 'already_in_progress';
    public const FAILED = 'failed';

    public function __construct(
        public readonly string $outcome,
        public readonly ?string $skipReason = null,
    ) {}

    public static function sent(): self
    {
        return new self(self::SENT);
    }

    public static function queued(): self
    {
        return new self(self::QUEUED);
    }

    public static function alreadySent(): self
    {
        return new self(self::ALREADY_SENT);
    }

    public static function alreadyInProgress(): self
    {
        return new self(self::ALREADY_IN_PROGRESS);
    }

    public static function failed(): self
    {
        return new self(self::FAILED);
    }

    public static function skipped(string $reason): self
    {
        return new self('skipped', $reason);
    }

    /**
     * True when no further action is warranted for this touch: it was
     * delivered now, delivered before, is in flight, or was refused by
     * policy. False only when a retry could legitimately be attempted.
     */
    public function isTerminalSuccess(): bool
    {
        return $this->outcome !== self::FAILED;
    }

    public function isSuccess(): bool
    {
        return in_array($this->outcome, [self::SENT, self::QUEUED, self::ALREADY_SENT, self::ALREADY_IN_PROGRESS], true);
    }
}
