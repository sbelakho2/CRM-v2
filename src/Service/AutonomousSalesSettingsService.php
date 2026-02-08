<?php

namespace App\Service;

use Symfony\Component\HttpKernel\KernelInterface;

class AutonomousSalesSettingsService
{
    private string $settingsPath;

    public function __construct(KernelInterface $kernel)
    {
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

    private function readSettings(): array
    {
        if (!file_exists($this->settingsPath)) {
            return ['enabled' => true];
        }

        $raw = file_get_contents($this->settingsPath);
        if ($raw === false) {
            return ['enabled' => true];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
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

        file_put_contents($this->settingsPath, json_encode($data, JSON_PRETTY_PRINT));
    }
}
