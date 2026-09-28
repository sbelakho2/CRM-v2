<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Real system-health probe for the UI status indicator.
 *
 * The status bar previously rendered "Operational" unconditionally — a
 * design element, not a measurement. This service measures the dimensions
 * that matter for this deployment (database reachability first; further
 * probes can be added) and caches the result for a short window so every
 * page render does not re-probe.
 */
class SystemHealthService
{
    public const STATUS_HEALTHY = 'healthy';
    public const STATUS_DEGRADED = 'degraded';

    private const CACHE_KEY = 'system_health.status';
    private const CACHE_TTL_SECONDS = 60;

    public function __construct(
        private Connection $connection,
        private CacheItemPoolInterface $cacheApp,
    ) {}

    /**
     * @return self::STATUS_*
     */
    public function getStatus(): string
    {
        $item = $this->cacheApp->getItem(self::CACHE_KEY);

        if ($item->isHit() && is_string($item->get())) {
            return $item->get();
        }

        $status = $this->probe();
        $item->set($status);
        $item->expiresAfter(self::CACHE_TTL_SECONDS);
        $this->cacheApp->save($item);

        return $status;
    }

    private function probe(): string
    {
        try {
            $this->connection->fetchOne('SELECT 1');
        } catch (\Throwable) {
            return self::STATUS_DEGRADED;
        }

        return self::STATUS_HEALTHY;
    }
}
