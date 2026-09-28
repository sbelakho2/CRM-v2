<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Thrown when an outbound URL is rejected by SafeOutboundUrlGuard.
 */
final class UnsafeOutboundUrlException extends \RuntimeException
{
}
