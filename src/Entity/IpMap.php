<?php

namespace App\Entity;

use App\Repository\IpMapRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: IpMapRepository::class)]
#[ORM\Table(name: 'ip_maps')]
#[ORM\Index(name: 'idx_ip_asof', columns: ['ip_address', 'asof'])]
class IpMap
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 45)]
    private ?string $ipAddress = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $organizationName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $domain = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $region = null;

    #[ORM\Column(length: 2, nullable: true)]
    private ?string $country = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $firmographicData = null; // JSON with industry, size, etc.

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $asof = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $expiresAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(string $ipAddress): self
    {
        $this->ipAddress = $ipAddress;
        return $this;
    }

    public function getOrganizationName(): ?string
    {
        return $this->organizationName;
    }

    public function setOrganizationName(?string $organizationName): self
    {
        $this->organizationName = $organizationName;
        return $this;
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    public function setDomain(?string $domain): self
    {
        $this->domain = $domain;
        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): self
    {
        $this->city = $city;
        return $this;
    }

    public function getRegion(): ?string
    {
        return $this->region;
    }

    public function setRegion(?string $region): self
    {
        $this->region = $region;
        return $this;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(?string $country): self
    {
        $this->country = $country;
        return $this;
    }

    public function getFirmographicData(): ?array
    {
        return $this->firmographicData;
    }

    public function setFirmographicData(?array $firmographicData): self
    {
        $this->firmographicData = $firmographicData;
        return $this;
    }

    public function getAsof(): ?\DateTimeInterface
    {
        return $this->asof;
    }

    public function setAsof(\DateTimeInterface $asof): self
    {
        $this->asof = $asof;
        return $this;
    }

    public function getExpiresAt(): ?\DateTimeInterface
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeInterface $expiresAt): self
    {
        $this->expiresAt = $expiresAt;
        return $this;
    }
}
