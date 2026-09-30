<?php

namespace App\Entity;

use App\Repository\RoutePreferenceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RoutePreferenceRepository::class)]
#[ORM\Table(name: 'route_preferences')]
#[ORM\Index(name: 'idx_destination_country', columns: ['destination_country'])]
class RoutePreference
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 2)]
    private ?string $destinationCountry = null;

    #[ORM\Column(length: 100)]
    private ?string $laneCode = null;

    // `rank` is a MySQL 8 reserved word — the mapping must quote it (the
    // migrations' DDL already does) or Doctrine's runtime INSERT/UPDATE fails.
    #[ORM\Column(name: '`rank`', type: 'integer')]
    private ?int $rank = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $originPort = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $destinationPort = null;

    #[ORM\Column(length: 50)]
    private ?string $mode = null; // AIR, OCEAN, RAIL, TRUCK

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $weightThresholdKg = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $volumeThresholdM3 = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isActive = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDestinationCountry(): ?string
    {
        return $this->destinationCountry;
    }

    public function setDestinationCountry(string $destinationCountry): self
    {
        $this->destinationCountry = $destinationCountry;
        return $this;
    }

    public function getLaneCode(): ?string
    {
        return $this->laneCode;
    }

    public function setLaneCode(string $laneCode): self
    {
        $this->laneCode = $laneCode;
        return $this;
    }

    public function getRank(): ?int
    {
        return $this->rank;
    }

    public function setRank(int $rank): self
    {
        $this->rank = $rank;
        return $this;
    }

    public function getOriginPort(): ?string
    {
        return $this->originPort;
    }

    public function setOriginPort(?string $originPort): self
    {
        $this->originPort = $originPort;
        return $this;
    }

    public function getDestinationPort(): ?string
    {
        return $this->destinationPort;
    }

    public function setDestinationPort(?string $destinationPort): self
    {
        $this->destinationPort = $destinationPort;
        return $this;
    }

    public function getMode(): ?string
    {
        return $this->mode;
    }

    public function setMode(string $mode): self
    {
        $this->mode = $mode;
        return $this;
    }

    public function getWeightThresholdKg(): ?string
    {
        return $this->weightThresholdKg;
    }

    public function setWeightThresholdKg(?string $weightThresholdKg): self
    {
        $this->weightThresholdKg = $weightThresholdKg;
        return $this;
    }

    public function getVolumeThresholdM3(): ?string
    {
        return $this->volumeThresholdM3;
    }

    public function setVolumeThresholdM3(?string $volumeThresholdM3): self
    {
        $this->volumeThresholdM3 = $volumeThresholdM3;
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

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        return $this;
    }
}
