<?php

namespace App\Entity;

use App\Repository\WorkerHeartbeatRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Liveness marker written by background workers on every completed run.
 *
 * The system-health probe reads these to distinguish "queue empty because
 * everything is processed" from "queue empty because no worker is alive":
 * a worker whose heartbeat is stale is reported degraded even when the
 * queue looks calm.
 */
#[ORM\Entity(repositoryClass: WorkerHeartbeatRepository::class)]
#[ORM\Table(name: 'worker_heartbeats')]
#[ORM\UniqueConstraint(name: 'uniq_worker_name', columns: ['name'])]
class WorkerHeartbeat
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $name = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $lastRunAt = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $runCount = 0;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $lastResult = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getLastRunAt(): ?\DateTimeInterface
    {
        return $this->lastRunAt;
    }

    public function setLastRunAt(\DateTimeInterface $lastRunAt): self
    {
        $this->lastRunAt = $lastRunAt;

        return $this;
    }

    public function getRunCount(): int
    {
        return $this->runCount;
    }

    public function setRunCount(int $runCount): self
    {
        $this->runCount = $runCount;

        return $this;
    }

    public function getLastResult(): ?string
    {
        return $this->lastResult;
    }

    public function setLastResult(?string $lastResult): self
    {
        $this->lastResult = $lastResult;

        return $this;
    }
}
