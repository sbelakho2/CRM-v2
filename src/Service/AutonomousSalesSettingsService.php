<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\KernelInterface;

class AutonomousSalesSettingsService
{
    private string $settingsPath;

    public function __construct(
        KernelInterface $kernel,
        private ?LoggerInterface $logger = null,
    ) {
        $this->settingsPath = $kernel->getProjectDir() . '/var/autonomous_sales_settings.json';
    }

    public function isEnabled(): bool
    {
        $data = $this->readSettings();

        return (bool) ($data['enabled'] ?? true);
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
            return ['enabled' => true];
        }

        $raw = file_get_contents($this->settingsPath);
        if ($raw === false) {
            $this->logger?->warning('Failed to read settings file', ['path' => $this->settingsPath]);
            return ['enabled' => true];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $this->logger?->warning('Settings file contains invalid JSON, resetting to defaults', ['path' => $this->settingsPath]);
            return ['enabled' => true];
        }

        return $data;
    }

    private function writeSettings(array $data): void
    {
        $dir = dirname($this->settingsPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $result = file_put_contents($this->settingsPath, json_encode($data, JSON_PRETTY_PRINT));
        if ($result === false) {
            $this->logger?->error('Failed to write settings file', ['path' => $this->settingsPath]);
        }
    }
}
