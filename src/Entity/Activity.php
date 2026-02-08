<?php

namespace App\Entity;

use App\Repository\ActivityRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ActivityRepository::class)]
#[ORM\Table(name: 'activities')]
#[ORM\HasLifecycleCallbacks]
class Activity
{
    // Standardized outcome categories for analytics
    public const OUTCOME_POSITIVE = 'positive';     // Meeting scheduled, interest expressed
    public const OUTCOME_NEUTRAL = 'neutral';       // Left voicemail, sent info
    public const OUTCOME_NEGATIVE = 'negative';     // Not interested, wrong contact
    public const OUTCOME_NO_RESPONSE = 'no_response'; // No answer, bounced email
    public const OUTCOME_PENDING = 'pending';       // Awaiting response
    
    public const OUTCOMES = [
        self::OUTCOME_POSITIVE,
        self::OUTCOME_NEUTRAL,
        self::OUTCOME_NEGATIVE,
        self::OUTCOME_NO_RESPONSE,
        self::OUTCOME_PENDING,
    ];
    
    // Activity statuses
    public const STATUS_OPEN = 'Open';
    public const STATUS_COMPLETED = 'Completed';
    public const STATUS_CANCELLED = 'Cancelled';
    public const STATUS_DEFERRED = 'Deferred';
    
    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_DEFERRED,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Company::class, inversedBy: 'activities')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Company $company = null;

    #[ORM\ManyToOne(targetEntity: Contact::class, inversedBy: 'activities')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Contact $contact = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'activities')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 50)]
    private ?string $type = null; // Call, Email, Meeting, Site Visit, Follow-up

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $subject = null; // Brief subject/title for the activity

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $outcome = null; // Standardized: positive, neutral, negative, no_response, pending

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $outcomeDetail = null; // Free text for specific outcome details

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $status = self::STATUS_OPEN; // Open, Completed, Cancelled, Deferred

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $durationMinutes = null; // Duration in minutes

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $activityDate = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $followUpDate = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
        $this->activityDate = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCompany(): ?Company
    {
        return $this->company;
    }

    public function setCompany(?Company $company): self
    {
        $this->company = $company;
        return $this;
    }

    public function getContact(): ?Contact
    {
        return $this->contact;
    }

    public function setContact(?Contact $contact): self
    {
        $this->contact = $contact;
        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;
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

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): self
    {
        $this->notes = $notes;
        return $this;
    }

    public function getOutcome(): ?string
    {
        return $this->outcome;
    }

    public function setOutcome(?string $outcome): self
    {
        $this->outcome = $outcome;
        return $this;
    }

    public function getActivityDate(): ?\DateTimeInterface
    {
        return $this->activityDate;
    }

    public function setActivityDate(\DateTimeInterface $activityDate): self
    {
        $this->activityDate = $activityDate;
        return $this;
    }

    public function getFollowUpDate(): ?\DateTimeInterface
    {
        return $this->followUpDate;
    }

    public function setFollowUpDate(?\DateTimeInterface $followUpDate): self
    {
        $this->followUpDate = $followUpDate;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): self
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

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTime();
    }
    
    // ============================================================
    // NEW METHODS for PlaybookEngine and enhanced analytics
    // ============================================================
    
    public function getSubject(): ?string
    {
        return $this->subject;
    }
    
    public function setSubject(?string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }
    
    public function getStatus(): ?string
    {
        return $this->status;
    }
    
    public function setStatus(?string $status): self
    {
        if ($status !== null) {
            // Try to normalize common variations first
            $normalized = ucfirst(strtolower($status));
            if (in_array($normalized, self::STATUSES, true)) {
                $status = $normalized;
            } elseif (!in_array($status, self::STATUSES, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Invalid activity status "%s". Valid statuses are: %s',
                    $status,
                    implode(', ', self::STATUSES)
                ));
            }
        }
        $this->status = $status;
        return $this;
    }
    
    public function getDurationMinutes(): ?int
    {
        return $this->durationMinutes;
    }
    
    public function setDurationMinutes(?int $durationMinutes): self
    {
        $this->durationMinutes = $durationMinutes;
        return $this;
    }
    
    /**
     * Alias for getDurationMinutes() for convenience
     */
    public function getDuration(): ?int
    {
        return $this->durationMinutes;
    }
    
    /**
     * Alias for setDurationMinutes() for convenience
     */
    public function setDuration(?int $minutes): self
    {
        return $this->setDurationMinutes($minutes);
    }
    
    /**
     * Get formatted duration string (e.g., "1h 30m")
     */
    public function getFormattedDuration(): string
    {
        if ($this->durationMinutes === null) {
            return 'N/A';
        }
        
        $hours = intdiv($this->durationMinutes, 60);
        $mins = $this->durationMinutes % 60;
        
        if ($hours > 0 && $mins > 0) {
            return sprintf('%dh %dm', $hours, $mins);
        } elseif ($hours > 0) {
            return sprintf('%dh', $hours);
        } else {
            return sprintf('%dm', $mins);
        }
    }
    
    public function getOutcomeDetail(): ?string
    {
        return $this->outcomeDetail;
    }
    
    public function setOutcomeDetail(?string $outcomeDetail): self
    {
        $this->outcomeDetail = $outcomeDetail;
        return $this;
    }
    
    /**
     * Set outcome with validation against standard categories
     */
    public function setOutcomeCategory(string $category): self
    {
        if (!in_array($category, self::OUTCOMES)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid outcome category "%s". Must be one of: %s', 
                    $category, 
                    implode(', ', self::OUTCOMES)
                )
            );
        }
        $this->outcome = $category;
        return $this;
    }
    
    /**
     * Check if activity has a positive outcome
     */
    public function isPositiveOutcome(): bool
    {
        return $this->outcome === self::OUTCOME_POSITIVE;
    }
    
    /**
     * Check if activity is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }
    
    /**
     * Check if activity is open/pending
     */
    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }
    
    /**
     * Mark activity as completed
     */
    public function complete(?string $outcome = null, ?string $outcomeDetail = null): self
    {
        $this->status = self::STATUS_COMPLETED;
        if ($outcome !== null) {
            $this->outcome = $outcome;
        }
        if ($outcomeDetail !== null) {
            $this->outcomeDetail = $outcomeDetail;
        }
        return $this;
    }
}
