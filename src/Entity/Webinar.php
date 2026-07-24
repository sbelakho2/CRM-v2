<?php

namespace App\Entity;

use App\Repository\WebinarRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WebinarRepository::class)]
#[ORM\Table(name: 'webinars')]
class Webinar
{
    public const STATUS_SCHEDULED = 'Scheduled';
    public const STATUS_COMPLETED = 'Completed';
    public const STATUS_CANCELLED = 'Cancelled';

    public const LANGUAGE_EN = 'EN';
    public const LANGUAGE_FR = 'FR';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(length: 10)]
    private ?string $language = self::LANGUAGE_EN; // EN or FR

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $scheduledDate = null;

    #[ORM\Column(type: 'integer')]
    private ?int $duration = 60; // in minutes

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $registrationUrl = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $recordingUrl = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $meetingUrl = null;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $registeredCount = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $attendedCount = 0;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $maxAttendees = null;

    #[ORM\OneToMany(mappedBy: 'webinar', targetEntity: WebinarAttendee::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $attendees;

    #[ORM\Column(length: 50)]
    private ?string $status = self::STATUS_SCHEDULED; // Scheduled, Completed, Cancelled

    public function __construct()
    {
        $this->attendees = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function setLanguage(string $language): self
    {
        $this->language = $language;
        return $this;
    }

    public function getScheduledDate(): ?\DateTimeInterface
    {
        return $this->scheduledDate;
    }

    public function setScheduledDate(\DateTimeInterface $scheduledDate): self
    {
        $this->scheduledDate = $scheduledDate;
        return $this;
    }

    public function getDuration(): ?int
    {
        return $this->duration;
    }

    public function setDuration(int $duration): self
    {
        $this->duration = $duration;
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

    public function getRegistrationUrl(): ?string
    {
        return $this->registrationUrl;
    }

    public function setRegistrationUrl(?string $registrationUrl): self
    {
        $this->registrationUrl = $registrationUrl;
        return $this;
    }

    public function getRecordingUrl(): ?string
    {
        return $this->recordingUrl;
    }

    public function setRecordingUrl(?string $recordingUrl): self
    {
        $this->recordingUrl = $recordingUrl;
        return $this;
    }

    public function getMeetingUrl(): ?string
    {
        return $this->meetingUrl;
    }

    public function setMeetingUrl(?string $meetingUrl): self
    {
        $this->meetingUrl = $meetingUrl;
        return $this;
    }

    public function getRegisteredCount(): int
    {
        return $this->registeredCount;
    }

    public function setRegisteredCount(int $registeredCount): self
    {
        $this->registeredCount = $registeredCount;
        return $this;
    }

    public function getAttendedCount(): int
    {
        return $this->attendedCount;
    }

    public function setAttendedCount(int $attendedCount): self
    {
        $this->attendedCount = $attendedCount;
        return $this;
    }

    public function getMaxAttendees(): ?int
    {
        return $this->maxAttendees;
    }

    public function setMaxAttendees(?int $maxAttendees): self
    {
        $this->maxAttendees = $maxAttendees;
        return $this;
    }

    /**
     * @return Collection<int, WebinarAttendee>
     */
    public function getAttendees(): Collection
    {
        return $this->attendees;
    }

    public function addAttendee(WebinarAttendee $attendee): self
    {
        if (!$this->attendees->contains($attendee)) {
            $this->attendees->add($attendee);
            $attendee->setWebinar($this);
        }
        return $this;
    }

    public function removeAttendee(WebinarAttendee $attendee): self
    {
        if ($this->attendees->removeElement($attendee)) {
            if ($attendee->getWebinar() === $this) {
                $attendee->setWebinar(null);
            }
        }
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
}
