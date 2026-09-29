<?php

namespace App\Entity;

use App\Repository\TaskRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TaskRepository::class)]
#[ORM\Table(name: 'tasks')]
#[ORM\Index(name: 'idx_task_status', columns: ['status'])]
#[ORM\Index(name: 'idx_task_due_date', columns: ['due_date'])]
#[ORM\Index(name: 'idx_task_assigned', columns: ['assigned_to_id'])]
#[ORM\Index(name: 'idx_task_priority', columns: ['priority'])]
#[ORM\HasLifecycleCallbacks]
class Task
{
    // Status constants
    public const STATUS_TODO = 'todo';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_REVIEW = 'review';
    public const STATUS_DONE = 'done';
    public const STATUS_CANCELLED = 'cancelled';
    
    public const STATUSES = [
        self::STATUS_TODO,
        self::STATUS_IN_PROGRESS,
        self::STATUS_REVIEW,
        self::STATUS_DONE,
        self::STATUS_CANCELLED,
    ];
    
    public const STATUS_LABELS = [
        self::STATUS_TODO => 'To Do',
        self::STATUS_IN_PROGRESS => 'In Progress',
        self::STATUS_REVIEW => 'Review',
        self::STATUS_DONE => 'Done',
        self::STATUS_CANCELLED => 'Cancelled',
    ];
    
    // Priority constants
    public const PRIORITY_LOW = 'low';
    public const PRIORITY_MEDIUM = 'medium';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_URGENT = 'urgent';
    
    public const PRIORITIES = [
        self::PRIORITY_LOW,
        self::PRIORITY_MEDIUM,
        self::PRIORITY_HIGH,
        self::PRIORITY_URGENT,
    ];
    
    public const PRIORITY_LABELS = [
        self::PRIORITY_LOW => 'Low',
        self::PRIORITY_MEDIUM => 'Medium',
        self::PRIORITY_HIGH => 'High',
        self::PRIORITY_URGENT => 'Urgent',
    ];
    
    // Task type constants
    public const TYPE_CALL = 'call';
    public const TYPE_EMAIL = 'email';
    public const TYPE_MEETING = 'meeting';
    public const TYPE_FOLLOW_UP = 'follow_up';
    public const TYPE_PROPOSAL = 'proposal';
    public const TYPE_RESEARCH = 'research';
    public const TYPE_ADMIN = 'admin';
    public const TYPE_OTHER = 'other';
    
    public const TYPES = [
        self::TYPE_CALL,
        self::TYPE_EMAIL,
        self::TYPE_MEETING,
        self::TYPE_FOLLOW_UP,
        self::TYPE_PROPOSAL,
        self::TYPE_RESEARCH,
        self::TYPE_ADMIN,
        self::TYPE_OTHER,
    ];
    
    public const TYPE_LABELS = [
        self::TYPE_CALL => 'Call',
        self::TYPE_EMAIL => 'Email',
        self::TYPE_MEETING => 'Meeting',
        self::TYPE_FOLLOW_UP => 'Follow-up',
        self::TYPE_PROPOSAL => 'Proposal',
        self::TYPE_RESEARCH => 'Research',
        self::TYPE_ADMIN => 'Admin',
        self::TYPE_OTHER => 'Other',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_TODO;

    #[ORM\Column(length: 20)]
    private string $priority = self::PRIORITY_MEDIUM;

    #[ORM\Column(length: 20)]
    private string $type = self::TYPE_OTHER;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dueDate = null;

    #[ORM\Column(type: Types::TIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dueTime = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $estimatedMinutes = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $actualMinutes = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $assignedTo = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?User $createdBy = null;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Company $company = null;

    #[ORM\ManyToOne(targetEntity: Contact::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Contact $contact = null;

    #[ORM\ManyToOne(targetEntity: RFQ::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?RFQ $rfq = null;

    #[ORM\ManyToOne(targetEntity: Lead::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Lead $lead = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $reminderAt = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $reminderSent = false;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isRecurring = false;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $recurringFrequency = null; // daily, weekly, monthly

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $tags = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $sortOrder = 0;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $completedAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $archivedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = $this->createdAt ?? new \DateTime();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        if ($status === self::STATUS_DONE && $this->completedAt === null) {
            $this->completedAt = new \DateTime();
        }
        return $this;
    }

    public function getStatusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function getPriority(): string
    {
        return $this->priority;
    }

    public function setPriority(string $priority): static
    {
        $this->priority = $priority;
        return $this;
    }

    public function getPriorityLabel(): string
    {
        return self::PRIORITY_LABELS[$this->priority] ?? $this->priority;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getTypeLabel(): string
    {
        return self::TYPE_LABELS[$this->type] ?? $this->type;
    }

    public function getDueDate(): ?\DateTimeInterface
    {
        return $this->dueDate;
    }

    public function setDueDate(?\DateTimeInterface $dueDate): static
    {
        $this->dueDate = $dueDate;
        return $this;
    }

    public function getDueTime(): ?\DateTimeInterface
    {
        return $this->dueTime;
    }

    public function setDueTime(?\DateTimeInterface $dueTime): static
    {
        $this->dueTime = $dueTime;
        return $this;
    }

    public function getEstimatedMinutes(): ?int
    {
        return $this->estimatedMinutes;
    }

    public function setEstimatedMinutes(?int $estimatedMinutes): static
    {
        $this->estimatedMinutes = $estimatedMinutes;
        return $this;
    }

    public function getActualMinutes(): ?int
    {
        return $this->actualMinutes;
    }

    public function setActualMinutes(?int $actualMinutes): static
    {
        $this->actualMinutes = $actualMinutes;
        return $this;
    }

    public function getAssignedTo(): ?User
    {
        return $this->assignedTo;
    }

    public function setAssignedTo(?User $assignedTo): static
    {
        $this->assignedTo = $assignedTo;
        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    public function getCompany(): ?Company
    {
        return $this->company;
    }

    public function setCompany(?Company $company): static
    {
        $this->company = $company;
        return $this;
    }

    public function getContact(): ?Contact
    {
        return $this->contact;
    }

    public function setContact(?Contact $contact): static
    {
        $this->contact = $contact;
        return $this;
    }

    public function getRfq(): ?RFQ
    {
        return $this->rfq;
    }

    public function setRfq(?RFQ $rfq): static
    {
        $this->rfq = $rfq;
        return $this;
    }

    public function getLead(): ?Lead
    {
        return $this->lead;
    }

    public function setLead(?Lead $lead): static
    {
        $this->lead = $lead;
        return $this;
    }

    public function getReminderAt(): ?\DateTimeInterface
    {
        return $this->reminderAt;
    }

    public function setReminderAt(?\DateTimeInterface $reminderAt): static
    {
        $this->reminderAt = $reminderAt;
        return $this;
    }

    public function isReminderSent(): bool
    {
        return $this->reminderSent;
    }

    public function setReminderSent(bool $reminderSent): static
    {
        $this->reminderSent = $reminderSent;
        return $this;
    }

    public function isRecurring(): bool
    {
        return $this->isRecurring;
    }

    public function setIsRecurring(bool $isRecurring): static
    {
        $this->isRecurring = $isRecurring;
        return $this;
    }

    public function getRecurringFrequency(): ?string
    {
        return $this->recurringFrequency;
    }

    public function setRecurringFrequency(?string $recurringFrequency): static
    {
        $this->recurringFrequency = $recurringFrequency;
        return $this;
    }

    public function getTags(): ?array
    {
        return $this->tags;
    }

    public function setTags(?array $tags): static
    {
        $this->tags = $tags;
        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getCompletedAt(): ?\DateTimeInterface
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeInterface $completedAt): static
    {
        $this->completedAt = $completedAt;
        return $this;
    }

    public function isOverdue(): bool
    {
        if ($this->status === self::STATUS_DONE || $this->status === self::STATUS_CANCELLED) {
            return false;
        }
        if ($this->dueDate === null) {
            return false;
        }
        return $this->dueDate < new \DateTime('today');
    }

    public function isDueToday(): bool
    {
        if ($this->dueDate === null) {
            return false;
        }
        return $this->dueDate->format('Y-m-d') === (new \DateTime())->format('Y-m-d');
    }

    public function isDueSoon(): bool
    {
        if ($this->dueDate === null) {
            return false;
        }
        $threeDaysFromNow = (new \DateTime())->modify('+3 days');
        return $this->dueDate <= $threeDaysFromNow && $this->dueDate >= new \DateTime('today');
    }

    /**
     * Get the related entity name for display
     */
    public function getRelatedEntityName(): ?string
    {
        if ($this->company) {
            return $this->company->getName();
        }
        if ($this->contact) {
            return $this->contact->getFullName();
        }
        if ($this->rfq) {
            return 'RFQ #' . $this->rfq->getId();
        }
        if ($this->lead) {
            return $this->lead->getCompanyName();
        }
        return null;
    }

    public function getArchivedAt(): ?\DateTimeInterface
    {
        return $this->archivedAt;
    }

    public function setArchivedAt(?\DateTimeInterface $archivedAt): self
    {
        $this->archivedAt = $archivedAt;

        return $this;
    }
}
