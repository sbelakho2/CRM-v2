<?php

namespace App\Service;

final class EmailTrackingSigner
{
    public function __construct(private string $secret)
    {
    }

    public function signOpen(int $sendId): string
    {
        return $this->signPayload($sendId . '|open');
    }

    public function verifyOpen(int $sendId, ?string $signature): bool
    {
        if (!$signature) {
            return false;
        }

        return hash_equals($this->signOpen($sendId), $signature);
    }

    public function signClick(int $sendId, string $url): string
    {
        return $this->signPayload($sendId . '|click|' . $url);
    }

    public function verifyClick(int $sendId, string $url, ?string $signature): bool
    {
        if (!$signature) {
            return false;
        }

        return hash_equals($this->signClick($sendId, $url), $signature);
    }

    private function signPayload(string $payload): string
    {
        // Hex output keeps it URL-safe without additional encoding.
        return hash_hmac('sha256', $payload, $this->secret);
    }
}
