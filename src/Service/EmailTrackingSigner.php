<?php

declare(strict_types=1);

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

        // EmailCampaignService signs the raw target URL and embeds it in a
        // route parameter. Email clients, proxies or servers may deliver
        // that parameter still percent-encoded, so accept both the raw form
        // and the urldecoded form (urlencode and rawurlencode variants).
        $candidates = [$url];
        if (urldecode($url) !== $url) {
            $candidates[] = urldecode($url);
        }
        if (rawurldecode($url) !== $url) {
            $candidates[] = rawurldecode($url);
        }

        foreach (array_unique($candidates) as $candidate) {
            if (hash_equals($this->signClick($sendId, $candidate), $signature)) {
                return true;
            }
        }

        return false;
    }

    private function signPayload(string $payload): string
    {
        // Hex output keeps it URL-safe without additional encoding.
        return hash_hmac('sha256', $payload, $this->secret);
    }
}
