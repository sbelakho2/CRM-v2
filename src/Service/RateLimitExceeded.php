<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Thrown when a public live-quote bucket (per token / per IP) is exhausted.
 * Controllers map this to HTTP 429.
 */
final class RateLimitExceeded extends \RuntimeException
{
}
