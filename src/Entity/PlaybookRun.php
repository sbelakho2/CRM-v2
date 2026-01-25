<?php

namespace App\Entity;

use App\Repository\PlaybookRunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlaybookRunRepository::class)]
#[ORM\Table(name: 'playbook_runs')]
#[ORM\Index(name: 'idx_playbook_abm_hit', columns: ['playbook_id', 'abm_hit_id'])]
class PlaybookRun
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Playbook::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Playbook $playbook = null;

    #[ORM\ManyToOne(targetEntity: AbmHit::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?AbmHit $abmHit = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $triggeredAt = null;

    #[ORM\Column(length: 50)]
    private ?string $status = null; // pending, in_progress, completed, failed

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $completedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $executionLog = null; // JSON with execution details

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $tasksCreated = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    public function __construct()
    {
        $this->triggeredAt = new \DateTime();
        $this->status = 'pending';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlaybook(): ?Playbook
    {
        return $this->playbook;
    }

    public function setPlaybook(?Playbook $playbook): self
    {
        $this->playbook = $playbook;
        return $this;
    }

    public function getAbmHit(): ?AbmHit
    {
        return $this->abmHit;
    }

    public function setAbmHit(?AbmHit $abmHit): self
    {
        $this->abmHit = $abmHit;
        return $this;
    }

    public function getTriggeredAt(): ?\DateTimeInterface
    {
        return $this->triggeredAt;
    }

    public function setTriggeredAt(\DateTimeInterface $triggeredAt): self
    {
        $this->triggeredAt = $triggeredAt;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getCompletedAt(): ?\DateTimeInterface
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeInterface $completedAt): self
    {
        $this->completedAt = $completedAt;
        return $this;
    }

    public function getExecutionLog(): ?string
    {
        return $this->executionLog;
    }

    public function setExecutionLog(?string $executionLog): self
    {
        $this->executionLog = $executionLog;
        return $this;
    }

    public function getTasksCreated(): ?int
    {
        return $this->tasksCreated;
    }

    public function setTasksCreated(?int $tasksCreated): self
    {
        $this->tasksCreated = $tasksCreated;
        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;
        return $this;
    }
}
