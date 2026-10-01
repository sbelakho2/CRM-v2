<?php

namespace App\Entity;

use App\Repository\CalendarEventRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CalendarEventRepository::class)]
#[ORM\Table(name: 'calendar_events')]
#[ORM\Index(columns: ['start_at'], name: 'idx_calendar_start')]
#[ORM\Index(columns: ['end_at'], name: 'idx_calendar_end')]
#[ORM\Index(columns: ['event_type'], name: 'idx_calendar_type')]
#[ORM\Index(columns: ['organizer_id'], name: 'idx_calendar_organizer')]
#[ORM\HasLifecycleCallbacks]
class CalendarEvent
{
    // Event Types
    public const TYPE_MEETING = 'meeting';
    public const TYPE_CALL = 'call';
    public const TYPE_DEMO = 'demo';
    public const TYPE_PRESENTATION = 'presentation';
    public const TYPE_FOLLOWUP = 'followup';
    public const TYPE_TASK = 'task';
    public const TYPE_REMINDER = 'reminder';
    public const TYPE_OUT_OF_OFFICE = 'out_of_office';
    public const TYPE_BLOCKED = 'blocked';
    public const TYPE_OTHER = 'other';

    // Visibility/Privacy
    public const VISIBILITY_PUBLIC = 'public';
    public const VISIBILITY_PRIVATE = 'private';
    public const VISIBILITY_TEAM = 'team';

    // Status
    public const STATUS_TENTATIVE = 'tentative';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_CANCELLED = 'cancelled';

    // Recurrence Frequencies
    public const RECUR_DAILY = 'daily';
    public const RECUR_WEEKLY = 'weekly';
    public const RECUR_BIWEEKLY = 'biweekly';
    public const RECUR_MONTHLY = 'monthly';
    public const RECUR_YEARLY = 'yearly';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Event title is required')]
    #[Assert\Length(max: 255)]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 50, name: 'event_type')]
    private string $eventType = self::TYPE_MEETING;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, name: 'start_at')]
    #[Assert\NotNull(message: 'Start time is required')]
    private ?\DateTimeInterface $startAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, name: 'end_at')]
    #[Assert\NotNull(message: 'End time is required')]
    #[Assert\GreaterThan(propertyPath: 'startAt', message: 'End time must be after start time')]
    private ?\DateTimeInterface $endAt = null;

    #[ORM\Column]
    private bool $allDay = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $location = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $meetingUrl = null;

    #[ORM\Column(length: 20)]
    private string $visibility = self::VISIBILITY_TEAM;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_CONFIRMED;

    #[ORM\Column(length: 7, nullable: true)]
    private ?string $color = null;

    // Recurrence settings
    #[ORM\Column]
    private bool $isRecurring = false;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $recurringFrequency = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $recurringUntil = null;

    #[ORM\Column(nullable: true)]
    private ?int $recurringCount = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $recurringDays = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'parent_event_id', onDelete: 'SET NULL')]
    private ?self $parentEvent = null;

    // Reminder settings
    #[ORM\Column(nullable: true)]
    private ?int $reminderMinutes = null;

    #[ORM\Column]
    private bool $reminderSent = false;

    // Relationships
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'organizer_id', nullable: false, onDelete: 'RESTRICT')]
    private ?User $organizer = null;

    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'calendar_event_attendees',
        joinColumns: [new ORM\JoinColumn(name: 'user_id', onDelete: 'CASCADE')],
        inverseJoinColumns: [new ORM\JoinColumn(name: 'calendar_event_id', onDelete: 'CASCADE')]
    )]
    private Collection $attendees;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', onDelete: 'SET NULL')]
    private ?Company $company = null;

    #[ORM\ManyToOne(targetEntity: Contact::class)]
    #[ORM\JoinColumn(name: 'contact_id', onDelete: 'SET NULL')]
    private ?Contact $contact = null;

    #[ORM\ManyToOne(targetEntity: Lead::class)]
    #[ORM\JoinColumn(name: 'lead_id', onDelete: 'SET NULL')]
    private ?Lead $lead = null;

    #[ORM\ManyToOne(targetEntity: RFQ::class)]
    #[ORM\JoinColumn(name: 'rfq_id', onDelete: 'SET NULL')]
    private ?RFQ $rfq = null;

    #[ORM\OneToOne(targetEntity: Task::class)]
    #[ORM\JoinColumn(name: 'task_id', onDelete: 'SET NULL')]
    private ?Task $task = null;

    // External calendar integration
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $externalId = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $externalProvider = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $lastSyncedAt = null;

    // Metadata
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->attendees = new ArrayCollection();
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTime();
    }

    public static function getTypes(): array
    {
        return [
            'Meeting' => self::TYPE_MEETING,
            'Call' => self::TYPE_CALL,
            'Demo' => self::TYPE_DEMO,
            'Presentation' => self::TYPE_PRESENTATION,
            'Follow-up' => self::TYPE_FOLLOWUP,
            'Task' => self::TYPE_TASK,
            'Reminder' => self::TYPE_REMINDER,
            'Out of Office' => self::TYPE_OUT_OF_OFFICE,
            'Blocked' => self::TYPE_BLOCKED,
            'Other' => self::TYPE_OTHER,
        ];
    }

    public static function getVisibilities(): array
    {
        return [
            'Public' => self::VISIBILITY_PUBLIC,
            'Private' => self::VISIBILITY_PRIVATE,
            'Team Only' => self::VISIBILITY_TEAM,
        ];
    }

    public static function getStatuses(): array
    {
        return [
            'Tentative' => self::STATUS_TENTATIVE,
            'Confirmed' => self::STATUS_CONFIRMED,
            'Cancelled' => self::STATUS_CANCELLED,
        ];
    }

    public static function getRecurrenceOptions(): array
    {
        return [
            'Daily' => self::RECUR_DAILY,
            'Weekly' => self::RECUR_WEEKLY,
            'Bi-weekly' => self::RECUR_BIWEEKLY,
            'Monthly' => self::RECUR_MONTHLY,
            'Yearly' => self::RECUR_YEARLY,
        ];
    }

    public function getTypeIcon(): string
    {
        return match($this->eventType) {
            self::TYPE_MEETING => 'users',
            self::TYPE_CALL => 'phone',
            self::TYPE_DEMO => 'desktop',
            self::TYPE_PRESENTATION => 'chalkboard-teacher',
            self::TYPE_FOLLOWUP => 'redo',
            self::TYPE_TASK => 'tasks',
            self::TYPE_REMINDER => 'bell',
            self::TYPE_OUT_OF_OFFICE => 'plane',
            self::TYPE_BLOCKED => 'ban',
            default => 'calendar',
        };
    }

    public function getTypeColor(): string
    {
        if ($this->color) {
            return $this->color;
        }

        return match($this->eventType) {
            self::TYPE_MEETING => '#3b82f6',
            self::TYPE_CALL => '#10b981',
            self::TYPE_DEMO => '#8b5cf6',
            self::TYPE_PRESENTATION => '#f59e0b',
            self::TYPE_FOLLOWUP => '#6366f1',
            self::TYPE_TASK => '#64748b',
            self::TYPE_REMINDER => '#ef4444',
            self::TYPE_OUT_OF_OFFICE => '#ec4899',
            self::TYPE_BLOCKED => '#1f2937',
            default => '#6b7280',
        };
    }

    public function getDurationMinutes(): int
    {
        if (!$this->startAt || !$this->endAt) {
            return 0;
        }
        
        $diff = $this->endAt->getTimestamp() - $this->startAt->getTimestamp();
        return (int) ($diff / 60);
    }

    public function getDurationFormatted(): string
    {
        $minutes = $this->getDurationMinutes();
        
        if ($minutes < 60) {
            return $minutes . ' min';
        }
        
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;
        
        if ($mins === 0) {
            return $hours . ' hr';
        }
        
        return $hours . ' hr ' . $mins . ' min';
    }

    public function isHappening(): bool
    {
        $now = new \DateTime();
        return $this->startAt <= $now && $this->endAt >= $now;
    }

    public function isPast(): bool
    {
        return $this->endAt < new \DateTime();
    }

    public function isUpcoming(): bool
    {
        return $this->startAt > new \DateTime();
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function needsReminder(): bool
    {
        if (!$this->reminderMinutes || $this->reminderSent || $this->isPast()) {
            return false;
        }

        if ($this->startAt === null) { return false; }

        $reminderTime = (clone $this->startAt)->modify("-{$this->reminderMinutes} minutes");
        $now = new \DateTime();

        return $now >= $reminderTime && $now < $this->startAt;
    }

    public function toFullCalendarEvent(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'start' => $this->startAt?->format('c') ?? '',
            'end' => $this->endAt?->format('c') ?? '',
            'allDay' => $this->allDay,
            'color' => $this->getTypeColor(),
            'extendedProps' => [
                'type' => $this->eventType,
                'status' => $this->status,
                'location' => $this->location,
                'description' => $this->description,
                'organizer' => $this->organizer?->getFullName(),
                'company' => $this->company?->getName(),
                'contact' => $this->contact?->getFullName(),
            ],
        ];
    }

    // Getters and Setters

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

    public function getEventType(): string
    {
        return $this->eventType;
    }

    public function setEventType(string $eventType): static
    {
        $this->eventType = $eventType;
        return $this;
    }

    public function getStartAt(): ?\DateTimeInterface
    {
        return $this->startAt;
    }

    public function setStartAt(\DateTimeInterface $startAt): static
    {
        $this->startAt = $startAt;
        return $this;
    }

    public function getEndAt(): ?\DateTimeInterface
    {
        return $this->endAt;
    }

    public function setEndAt(\DateTimeInterface $endAt): static
    {
        $this->endAt = $endAt;
        return $this;
    }

    public function isAllDay(): bool
    {
        return $this->allDay;
    }

    public function setAllDay(bool $allDay): static
    {
        $this->allDay = $allDay;
        return $this;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): static
    {
        $this->location = $location;
        return $this;
    }

    public function getMeetingUrl(): ?string
    {
        return $this->meetingUrl;
    }

    public function setMeetingUrl(?string $meetingUrl): static
    {
        $this->meetingUrl = $meetingUrl;
        return $this;
    }

    public function getVisibility(): string
    {
        return $this->visibility;
    }

    public function setVisibility(string $visibility): static
    {
        $this->visibility = $visibility;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): static
    {
        $this->color = $color;
        return $this;
    }

    public function getIsRecurring(): bool
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

    public function getRecurringUntil(): ?\DateTimeInterface
    {
        return $this->recurringUntil;
    }

    public function setRecurringUntil(?\DateTimeInterface $recurringUntil): static
    {
        $this->recurringUntil = $recurringUntil;
        return $this;
    }

    public function getRecurringCount(): ?int
    {
        return $this->recurringCount;
    }

    public function setRecurringCount(?int $recurringCount): static
    {
        $this->recurringCount = $recurringCount;
        return $this;
    }

    public function getRecurringDays(): ?array
    {
        return $this->recurringDays;
    }

    public function setRecurringDays(?array $recurringDays): static
    {
        $this->recurringDays = $recurringDays;
        return $this;
    }

    public function getParentEvent(): ?self
    {
        return $this->parentEvent;
    }

    public function setParentEvent(?self $parentEvent): static
    {
        $this->parentEvent = $parentEvent;
        return $this;
    }

    public function getReminderMinutes(): ?int
    {
        return $this->reminderMinutes;
    }

    public function setReminderMinutes(?int $reminderMinutes): static
    {
        $this->reminderMinutes = $reminderMinutes;
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

    public function getOrganizer(): ?User
    {
        return $this->organizer;
    }

    public function setOrganizer(?User $organizer): static
    {
        $this->organizer = $organizer;
        return $this;
    }

    /**
     * @return Collection<int, User>
     */
    public function getAttendees(): Collection
    {
        return $this->attendees;
    }

    public function addAttendee(User $attendee): static
    {
        if (!$this->attendees->contains($attendee)) {
            $this->attendees->add($attendee);
        }
        return $this;
    }

    public function removeAttendee(User $attendee): static
    {
        $this->attendees->removeElement($attendee);
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

    public function getLead(): ?Lead
    {
        return $this->lead;
    }

    public function setLead(?Lead $lead): static
    {
        $this->lead = $lead;
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

    public function getTask(): ?Task
    {
        return $this->task;
    }

    public function setTask(?Task $task): static
    {
        $this->task = $task;
        return $this;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(?string $externalId): static
    {
        $this->externalId = $externalId;
        return $this;
    }

    public function getExternalProvider(): ?string
    {
        return $this->externalProvider;
    }

    public function setExternalProvider(?string $externalProvider): static
    {
        $this->externalProvider = $externalProvider;
        return $this;
    }

    public function getLastSyncedAt(): ?\DateTimeInterface
    {
        return $this->lastSyncedAt;
    }

    public function setLastSyncedAt(?\DateTimeInterface $lastSyncedAt): static
    {
        $this->lastSyncedAt = $lastSyncedAt;
        return $this;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): static
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
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

    public function setUpdatedAt(\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
}
