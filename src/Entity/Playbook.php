<?php

namespace App\Entity;

use App\Repository\PlaybookRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlaybookRepository::class)]
#[ORM\Table(name: 'playbooks')]
class Playbook
{
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $archivedAt = null;

    #[ORM\ManyToOne(targetEntity: \App\Entity\User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?\App\Entity\User $archivedBy = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $archiveReason = null;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $triggerRules = null; // JSON with trigger conditions

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $actions = null; // JSON with actions to execute

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $priority = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    #[ORM\Column(type: 'integer', nullable: true, options: ['default' => 24])]
    private ?int $cooldownHours = 24; // Prevent re-triggering within this window

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\OneToMany(mappedBy: 'playbook', targetEntity: PlaybookRun::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $playbookRuns;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        $this->playbookRuns = new ArrayCollection();
    }

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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getTriggerRules(): ?string
    {
        return $this->triggerRules;
    }

    public function setTriggerRules(string $triggerRules): self
    {
        $this->triggerRules = $triggerRules;
        return $this;
    }

    public function getActions(): ?string
    {
        return $this->actions;
    }

    public function setActions(string $actions): self
    {
        $this->actions = $actions;
        return $this;
    }

    public function getPriority(): ?int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): self
    {
        $this->priority = $priority;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeInterface $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;
        return $this;
    }

    /**
     * @return Collection<int, PlaybookRun>
         /** @return Collection<int, App\Entity\PlaybookRun> */
    public function getPlaybookRuns(): Collection
    {
        return $this->playbookRuns;
    }

    public function addPlaybookRun(PlaybookRun $playbookRun): self
    {
        if (!$this->playbookRuns->contains($playbookRun)) {
            $this->playbookRuns->add($playbookRun);
            $playbookRun->setPlaybook($this);
        }

        return $this;
    }

    public function removePlaybookRun(PlaybookRun $playbookRun): self
    {
        if ($this->playbookRuns->removeElement($playbookRun)) {
            if ($playbookRun->getPlaybook() === $this) {
                $playbookRun->setPlaybook(null);
            }
        }

        return $this;
    }
    
    // ============================================================
    // COOLDOWN MANAGEMENT - Prevents playbook re-triggering
    // ============================================================
    
    public function getCooldownHours(): ?int
    {
        return $this->cooldownHours;
    }
    
    public function setCooldownHours(?int $cooldownHours): self
    {
        $this->cooldownHours = $cooldownHours;
        return $this;
    }
    
    /**
     * Get cooldown period as DateInterval
     */
    public function getCooldownInterval(): \DateInterval
    {
        $hours = $this->cooldownHours ?? 24;
        return new \DateInterval("PT{$hours}H");
    }
    
    /**
     * Check if cooldown period has elapsed since given time
     */
    public function isCooldownComplete(\DateTimeInterface $lastTriggered): bool
    {
        $cooldownHours = $this->cooldownHours ?? 24;
        $cooldownEnd = (clone $lastTriggered)->modify("+{$cooldownHours} hours");
        return new \DateTime() >= $cooldownEnd;
    }
    public function isArchived(): bool
    {
        return $this->archivedAt !== null;
    }

    public function archive(\App\Entity\User $by, ?string $reason = null): self
    {
        $this->archivedAt = $this->archivedAt ?? new \DateTime();
        $this->archivedBy = $by;
        $this->archiveReason = $reason;

        return $this;
    }

    public function getArchivedAt(): ?\DateTimeInterface
    {
        return $this->archivedAt;
    }

    public function getArchivedBy(): ?\App\Entity\User
    {
        return $this->archivedBy;
    }

    public function getArchiveReason(): ?string
    {
        return $this->archiveReason;
    }

}
