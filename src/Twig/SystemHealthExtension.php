<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\SystemHealthService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the real system-health status to templates
 * ({% set health = system_health() %}).
 */
class SystemHealthExtension extends AbstractExtension
{
    public function __construct(
        private SystemHealthService $health,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('system_health', [$this, 'health']),
        ];
    }

    public function health(): string
    {
        return $this->health->getStatus();
    }
}
