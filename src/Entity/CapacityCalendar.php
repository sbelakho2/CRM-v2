<?php

namespace App\Entity;

use App\Repository\CapacityCalendarRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CapacityCalendarRepository::class)]
#[ORM\Table(name: 'capacity_calendars')]
#[ORM\Index(name: 'idx_production_date', columns: ['production_date'])]
class CapacityCalendar
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $productionDate = null;

    #[ORM\Column(type: 'integer')]
    private ?int $availableSlots = null;

    #[ORM\Column(type: 'integer')]
    private ?int $bookedSlots = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isHoliday = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $holidayName = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    /**
     * Structured booking ledger for the day (quoteId, quantity, bookedAt) —
     * supports reservation audit and cancellation by quote.
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $bookings = [];

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProductionDate(): ?\DateTimeInterface
    {
        return $this->productionDate;
    }

    public function setProductionDate(\DateTimeInterface $productionDate): self
    {
        $this->productionDate = $productionDate;
        return $this;
    }

    public function getAvailableSlots(): ?int
    {
        return $this->availableSlots;
    }

    public function setAvailableSlots(int $availableSlots): self
    {
        $this->availableSlots = $availableSlots;
        return $this;
    }

    public function getBookedSlots(): ?int
    {
        return $this->bookedSlots;
    }

    public function setBookedSlots(int $bookedSlots): self
    {
        $this->bookedSlots = $bookedSlots;
        return $this;
    }

    public function isHoliday(): bool
    {
        return $this->isHoliday;
    }

    public function setIsHoliday(bool $isHoliday): self
    {
        $this->isHoliday = $isHoliday;
        return $this;
    }

    public function getHolidayName(): ?string
    {
        return $this->holidayName;
    }

    public function setHolidayName(?string $holidayName): self
    {
        $this->holidayName = $holidayName;
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

    public function getRemainingSlots(): ?int
    {
        if ($this->availableSlots === null || $this->bookedSlots === null) { return null; }
        return $this->availableSlots - $this->bookedSlots;
    }
    public function getBookings(): array
    {
        return $this->bookings ?? [];
    }

    /**
     * @param array<string|int, mixed> $bookings
     */
    public /**
 * @param array<string|int, mixed> $bookings
 */
function setBookings(?array $bookings): self
    {
        $this->bookings = $bookings;

        return $this;
    }

    public function addBooking(int $quoteId, int $quantityBoards): self
    {
        $bookings = $this->bookings ?? [];
        $bookings[] = [
            'quoteId' => $quoteId,
            'quantity' => $quantityBoards,
            'bookedAt' => (new \DateTime())->format('Y-m-d H:i:s'),
        ];
        $this->bookings = $bookings;

        return $this;
    }

}
