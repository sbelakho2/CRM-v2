<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraints\Compound;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotCompromisedPassword;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Single source of truth for account password strength, applied identically
 * to registration, admin user creation and password changes/resets.
 * (Compound constraints are plain subclasses — no attribute needed.)
 */
class PasswordPolicy extends Compound
{
    public const MIN_LENGTH = 12;

    /**
     * @param array<string|int, mixed> $options
     */
    protected /**
 * @param array<string|int, mixed> $options
 */
function getConstraints(array $options): array
    {
        return [
            new NotBlank(['message' => 'validation.required']),
            new Length([
                'min' => self::MIN_LENGTH,
                'max' => 4096,
                'minMessage' => 'validation.password_min_length',
            ]),
            // Reject passwords known to appear in public data breaches. On
            // environments without network access the check degrades to a
            // pass rather than blocking every password (Symfony behavior).
            new NotCompromisedPassword(['skipOnError' => true]),
        ];
    }
}
