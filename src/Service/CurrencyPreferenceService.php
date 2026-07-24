<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;

class CurrencyPreferenceService
{
    public function __construct(private Security $security)
    {
    }

    public function getDisplayCurrency(?string $fallback = null): string
    {
        $user = $this->security->getUser();
        if ($user instanceof User) {
            $currency = $user->getDisplayCurrency();
            if ($currency) {
                return strtoupper($currency);
            }
        }

        $fallbackCurrency = $fallback ?? ($_ENV['DEFAULT_CURRENCY'] ?? 'USD');

        return strtoupper($fallbackCurrency);
    }
}
