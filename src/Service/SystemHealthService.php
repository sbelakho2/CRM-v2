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
        private string $projectDir = '',
    ) {}

    /**
     * Per-probe results ('healthy'|'degraded'|'unknown') for tooling; the
     * UI uses the cached aggregate via getStatus().
     *
     * @return array<string, string>
     */
    public function getDetails(): array
    {
        $item = $this->cacheApp->getItem(self::CACHE_KEY.'.details');
        if ($item->isHit() && is_array($item->get())) {
            return $item->get();
        }

        $details = $this->probeDetails();
        $item->set($details);
        $item->expiresAfter(self::CACHE_TTL_SECONDS);
        $this->cacheApp->save($item);

        return $details;
    }

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
        $details = $this->probeDetails();

        return in_array(self::STATUS_DEGRADED, $details, true)
            ? self::STATUS_DEGRADED
            : self::STATUS_HEALTHY;
    }

    /**
     * @return array<string, string>
     */
    private function probeDetails(): array
    {
        $details = [];

        // Database
        try {
            $this->connection->fetchOne('SELECT 1');
            $details['database'] = self::STATUS_HEALTHY;
        } catch (\Throwable) {
            $details['database'] = self::STATUS_DEGRADED;
            return $details; // everything else needs the DB anyway
        }

        // Storage: the app must be able to write its var/ tree (logs,
        // uploads, exports).
        $varDir = $this->projectDir.'/var';
        $details['storage'] = (is_dir($varDir) && is_writable($varDir))
            ? self::STATUS_HEALTHY
            : self::STATUS_DEGRADED;

        // Messenger failed queue: a growing failed queue means workers are
        // losing messages (missing table => unknown, e.g. before setup).
        try {
            $failed = $this->connection->fetchOne(
                "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'failed'"
            );
            $details['messenger'] = ((int) $failed > 100)
                ? self::STATUS_DEGRADED
                : self::STATUS_HEALTHY;
        } catch (\Throwable) {
            $details['messenger'] = 'unknown';
        }

        // FX rate freshness (only meaningful once FX data exists).
        try {
            $newest = $this->connection->fetchOne('SELECT MAX(created_at) FROM fx_rates');
            if ($newest === null) {
                $details['fx'] = 'unknown';
            } else {
                $ageDays = (time() - strtotime((string) $newest)) / 86400;
                $details['fx'] = ($ageDays > 7) ? self::STATUS_DEGRADED : self::STATUS_HEALTHY;
            }
        } catch (\Throwable) {
            $details['fx'] = 'unknown';
        }

        return $details;
    }
}
