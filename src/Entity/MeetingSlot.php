<?php

namespace App\Entity;

use App\Repository\MeetingSlotRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use DateTimeImmutable;

#[ORM\Entity(repositoryClass: MeetingSlotRepository::class)]
#[ORM\Table(name: 'meeting_slots')]
#[ORM\HasLifecycleCallbacks]
class MeetingSlot
{
    // Slot Status
    public const STATUS_AVAILABLE = 'available';
    public const STATUS_BOOKED = 'booked';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_CANCELLED = 'cancelled';
    
    // Meeting Types
    public const TYPE_INTRODUCTION = 'introduction';
    public const TYPE_DEMO = 'demo';
    public const TYPE_CONSULTATION = 'consultation';
    public const TYPE_FOLLOW_UP = 'follow_up';
    public const TYPE_TECHNICAL = 'technical';
    public const TYPE_CUSTOM = 'custom';
    
    // Duration options (in minutes)
    public const DURATION_15 = 15;
    public const DURATION_30 = 30;
    public const DURATION_45 = 45;
    public const DURATION_60 = 60;
    public const DURATION_90 = 90;
    public const DURATION_120 = 120;
    
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    protected ?int $id = null;
    
    #[ORM\Column(length: 255)]
    private ?string $title = null;
    
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;
    
    #[ORM\Column(length: 50)]
    private string $meetingType = self::TYPE_INTRODUCTION;
    
    #[ORM\Column]
    private int $durationMinutes = self::DURATION_30;
    
    #[ORM\Column]
    private ?DateTimeImmutable $startTime = null;
    
    #[ORM\Column]
    private ?DateTimeImmutable $endTime = null;
    
    #[ORM\Column(length: 50)]
    private string $status = self::STATUS_AVAILABLE;
    
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $location = null;
    
    #[ORM\Column(length: 500, nullable: true)]
    private ?string $meetingUrl = null;
    
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $meetingProvider = null; // zoom, teams, google_meet, etc.
    
    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $meetingCredentials = null;
    
    // Owner of this slot (the sales rep offering the meeting)
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?User $owner = null;
    
    // Booking information
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $bookedByName = null;
    
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $bookedByEmail = null;
    
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $bookedByPhone = null;
    
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $bookedByCompany = null;
    
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $bookingNotes = null;
    
    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $bookedAt = null;
    
    #[ORM\Column(length: 100, unique: true, nullable: true)]
    private ?string $bookingToken = null;
    
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $cancellationToken = null;
    
    // Link to contact if exists
    #[ORM\ManyToOne(targetEntity: Contact::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Contact $contact = null;
    
    // Link to company if exists
    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Company $company = null;
    
    // Reminders
    #[ORM\Column]
    private bool $reminderSent = false;
    
    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $reminderSentAt = null;
    
    #[ORM\Column]
    private bool $confirmationSent = false;
    
    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $confirmationSentAt = null;
    
    // Timezone
    #[ORM\Column(length: 50)]
    private string $timezone = 'UTC';
    
    #[ORM\Column]
    private ?DateTimeImmutable $createdAt = null;
    
    #[ORM\Column]
    private ?DateTimeImmutable $updatedAt = null;
    
    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
        $this->updatedAt = new DateTimeImmutable();
    }
    
    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new DateTimeImmutable();
    }
    
    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if (!$this->bookingToken) {
            $this->bookingToken = bin2hex(random_bytes(32));
        }
        if (!$this->cancellationToken) {
            $this->cancellationToken = bin2hex(random_bytes(32));
        }
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
    
    public function getMeetingType(): ?string
    {
        return $this->meetingType;
    }
    
    public function setMeetingType(string $meetingType): static
    {
        $this->meetingType = $meetingType;
        return $this;
    }
    
    public function getDurationMinutes(): ?int
    {
        return $this->durationMinutes;
    }
    
    public function setDurationMinutes(int $durationMinutes): static
    {
        $this->durationMinutes = $durationMinutes;
        return $this;
    }
    
    public function getStartTime(): ?DateTimeImmutable
    {
        return $this->startTime;
    }
    
    public function setStartTime(DateTimeImmutable $startTime): static
    {
        $this->startTime = $startTime;
        return $this;
    }
    
    public function getEndTime(): ?DateTimeImmutable
    {
        return $this->endTime;
    }
    
    public function setEndTime(DateTimeImmutable $endTime): static
    {
        $this->endTime = $endTime;
        return $this;
    }
    
    public function getStatus(): ?string
    {
        return $this->status;
    }
    
    public function setStatus(string $status): static
    {
        $this->status = $status;
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
    
    public function getMeetingProvider(): ?string
    {
        return $this->meetingProvider;
    }
    
    public function setMeetingProvider(?string $meetingProvider): static
    {
        $this->meetingProvider = $meetingProvider;
        return $this;
    }
    
    /** @return array<string, mixed>|null */
    public function getMeetingCredentials(): ?array
    {
        return $this->meetingCredentials;
    }
    
    /** @param array<string, mixed>|null $meetingCredentials */
    public function setMeetingCredentials(?array $meetingCredentials): static
    {
        $this->meetingCredentials = $meetingCredentials;
        return $this;
    }
    
    public function getOwner(): ?User
    {
        return $this->owner;
    }
    
    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;
        return $this;
    }
    
    public function getBookedByName(): ?string
    {
        return $this->bookedByName;
    }
    
    public function setBookedByName(?string $bookedByName): static
    {
        $this->bookedByName = $bookedByName;
        return $this;
    }
    
    public function getBookedByEmail(): ?string
    {
        return $this->bookedByEmail;
    }
    
    public function setBookedByEmail(?string $bookedByEmail): static
    {
        $this->bookedByEmail = $bookedByEmail;
        return $this;
    }
    
    public function getBookedByPhone(): ?string
    {
        return $this->bookedByPhone;
    }
    
    public function setBookedByPhone(?string $bookedByPhone): static
    {
        $this->bookedByPhone = $bookedByPhone;
        return $this;
    }
    
    public function getBookedByCompany(): ?string
    {
        return $this->bookedByCompany;
    }
    
    public function setBookedByCompany(?string $bookedByCompany): static
    {
        $this->bookedByCompany = $bookedByCompany;
        return $this;
    }
    
    public function getBookingNotes(): ?string
    {
        return $this->bookingNotes;
    }
    
    public function setBookingNotes(?string $bookingNotes): static
    {
        $this->bookingNotes = $bookingNotes;
        return $this;
    }
    
    public function getBookedAt(): ?DateTimeImmutable
    {
        return $this->bookedAt;
    }
    
    public function setBookedAt(?DateTimeImmutable $bookedAt): static
    {
        $this->bookedAt = $bookedAt;
        return $this;
    }
    
    public function getBookingToken(): ?string
    {
        return $this->bookingToken;
    }
    
    public function setBookingToken(?string $bookingToken): static
    {
        $this->bookingToken = $bookingToken;
        return $this;
    }
    
    public function getCancellationToken(): ?string
    {
        return $this->cancellationToken;
    }
    
    public function setCancellationToken(?string $cancellationToken): static
    {
        $this->cancellationToken = $cancellationToken;
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
    
    public function getCompany(): ?Company
    {
        return $this->company;
    }
    
    public function setCompany(?Company $company): static
    {
        $this->company = $company;
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
    
    public function getReminderSentAt(): ?DateTimeImmutable
    {
        return $this->reminderSentAt;
    }
    
    public function setReminderSentAt(?DateTimeImmutable $reminderSentAt): static
    {
        $this->reminderSentAt = $reminderSentAt;
        return $this;
    }
    
    public function isConfirmationSent(): bool
    {
        return $this->confirmationSent;
    }
    
    public function setConfirmationSent(bool $confirmationSent): static
    {
        $this->confirmationSent = $confirmationSent;
        return $this;
    }
    
    public function getConfirmationSentAt(): ?DateTimeImmutable
    {
        return $this->confirmationSentAt;
    }
    
    public function setConfirmationSentAt(?DateTimeImmutable $confirmationSentAt): static
    {
        $this->confirmationSentAt = $confirmationSentAt;
        return $this;
    }
    
    public function getTimezone(): string
    {
        return $this->timezone;
    }
    
    public function setTimezone(string $timezone): static
    {
        $this->timezone = $timezone;
        return $this;
    }
    
    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }
    
    public function setCreatedAt(DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }
    
    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }
    
    public function setUpdatedAt(DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
    
    // Helper methods
    
    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_AVAILABLE;
    }
    
    public function isBooked(): bool
    {
        return $this->status === self::STATUS_BOOKED;
    }
    
    public function isBlocked(): bool
    {
        return $this->status === self::STATUS_BLOCKED;
    }
    
    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
    
    public function isPast(): bool
    {
        return $this->startTime < new DateTimeImmutable();
    }
    
    public function isUpcoming(): bool
    {
        return !$this->isPast() && $this->isBooked();
    }
    
    public function book(string $name, string $email, ?string $phone = null, ?string $company = null, ?string $notes = null): static
    {
        $this->status = self::STATUS_BOOKED;
        $this->bookedByName = $name;
        $this->bookedByEmail = $email;
        $this->bookedByPhone = $phone;
        $this->bookedByCompany = $company;
        $this->bookingNotes = $notes;
        $this->bookedAt = new DateTimeImmutable();
        
        return $this;
    }
    
    public function cancel(): static
    {
        $this->status = self::STATUS_CANCELLED;
        return $this;
    }
    
    public function makeAvailable(): static
    {
        $this->status = self::STATUS_AVAILABLE;
        $this->bookedByName = null;
        $this->bookedByEmail = null;
        $this->bookedByPhone = null;
        $this->bookedByCompany = null;
        $this->bookingNotes = null;
        $this->bookedAt = null;
        $this->contact = null;
        
        return $this;
    }
    
    public function getFormattedDuration(): string
    {
        if ($this->durationMinutes >= 60) {
            $hours = floor($this->durationMinutes / 60);
            $mins = $this->durationMinutes % 60;
            return $mins > 0 ? "{$hours}h {$mins}m" : "{$hours}h";
        }
        return "{$this->durationMinutes}m";
    }
    
    public function getMeetingTypeIcon(): string
    {
        return match($this->meetingType) {
            self::TYPE_INTRODUCTION => 'handshake',
            self::TYPE_DEMO => 'desktop',
            self::TYPE_CONSULTATION => 'comments',
            self::TYPE_FOLLOW_UP => 'redo',
            self::TYPE_TECHNICAL => 'cogs',
            self::TYPE_CUSTOM => 'calendar-check',
            default => 'calendar'
        };
    }
    
    public function getStatusBadgeClass(): string
    {
        return match($this->status) {
            self::STATUS_AVAILABLE => 'success',
            self::STATUS_BOOKED => 'primary',
            self::STATUS_BLOCKED => 'warning',
            self::STATUS_CANCELLED => 'danger',
            default => 'secondary'
        };
    }
    
    /** @return array<string, string> */
    public static function getMeetingTypes(): array
    {
        return [
            'Introduction Call' => self::TYPE_INTRODUCTION,
            'Product Demo' => self::TYPE_DEMO,
            'Consultation' => self::TYPE_CONSULTATION,
            'Follow-up' => self::TYPE_FOLLOW_UP,
            'Technical Discussion' => self::TYPE_TECHNICAL,
            'Custom' => self::TYPE_CUSTOM,
        ];
    }
    
    /** @return array<string, int> */
    public static function getDurations(): array
    {
        return [
            '15 minutes' => self::DURATION_15,
            '30 minutes' => self::DURATION_30,
            '45 minutes' => self::DURATION_45,
            '1 hour' => self::DURATION_60,
            '1.5 hours' => self::DURATION_90,
            '2 hours' => self::DURATION_120,
        ];
    }
    
    /** @return array<string, string> */
    public static function getStatuses(): array
    {
        return [
            'Available' => self::STATUS_AVAILABLE,
            'Booked' => self::STATUS_BOOKED,
            'Blocked' => self::STATUS_BLOCKED,
            'Cancelled' => self::STATUS_CANCELLED,
        ];
    }
}
