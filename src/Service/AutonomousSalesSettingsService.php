<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\KernelInterface;

class AutonomousSalesSettingsService
{
    private const SETTINGS_PATH_SUFFIX = '/var/autonomous_sales_settings.json';

    private string $settingsPath;

    public function __construct(
        KernelInterface $kernel,
        private ?LoggerInterface $logger = null,
    ) {
        $this->settingsPath = $kernel->getProjectDir() . self::SETTINGS_PATH_SUFFIX;
    }

    public function isEnabled(): bool
    {
        $data = $this->readSettings();

        // Fail closed: missing/corrupt settings default to DISABLED
        return (bool) ($data['enabled'] ?? false);
    }

    public function setEnabled(bool $enabled): void
    {
        $data = $this->readSettings();
        $data['enabled'] = $enabled;

        $this->writeSettings($data);
    }

    public function toggle(): bool
    {
        $enabled = !$this->isEnabled();
        $this->setEnabled($enabled);

        return $enabled;
    }

    public function getSetting(string $key, mixed $default = null): mixed
    {
        $data = $this->readSettings();

        return $data[$key] ?? $default;
    }

    public function setSetting(string $key, mixed $value): void
    {
        $data = $this->readSettings();
        $data[$key] = $value;

        $this->writeSettings($data);
    }

    private function readSettings(): array
    {
        if (!file_exists($this->settingsPath)) {
            $this->logger?->warning('Autonomous sales settings file not found, defaulting to DISABLED', ['path' => $this->settingsPath]);
            return ['enabled' => false];
        }

        $raw = file_get_contents($this->settingsPath);
        if ($raw === false) {
            $this->logger?->warning('Failed to read settings file, defaulting to DISABLED', ['path' => $this->settingsPath]);
            return ['enabled' => false];
        }

        /** @var array<string, mixed>|null $data */
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->logger?->warning('Settings file contains invalid JSON, defaulting to DISABLED', ['path' => $this->settingsPath]);
            return ['enabled' => false];
        }

        return $data;
    }

    /**
     * @param array<string|int, mixed> $data
     */
    private /**
 * @param array<string|int, mixed> $data
 */
function writeSettings(array $data): void
    {
        $dir = dirname($this->settingsPath);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->logger?->error('Failed to create settings directory', ['path' => $dir]);
            return;
        }

        // Atomic write: write to a temp file, then rename into place.
        // This prevents a concurrent reader (or a crash mid-write) from
        // observing a truncated/corrupt settings file.
        $tmpPath = $this->settingsPath . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $payload = json_encode($data, JSON_PRETTY_PRINT);

        if ($payload === false || @file_put_contents($tmpPath, $payload) === false) {
            @unlink($tmpPath);
            $this->logger?->error('Failed to write settings file', ['path' => $this->settingsPath]);
            return;
        }

        if (!@rename($tmpPath, $this->settingsPath)) {
            @unlink($tmpPath);
            $this->logger?->error('Failed to atomically replace settings file', ['path' => $this->settingsPath]);
        }
    }
}
