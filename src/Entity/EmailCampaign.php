<?php

namespace App\Entity;

use App\Repository\EmailCampaignRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EmailCampaignRepository::class)]
#[ORM\Table(name: 'email_campaigns')]
class EmailCampaign
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 10)]
    private ?string $language = 'EN'; // EN or FR

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: 'integer')]
    private ?int $touchCount = 5; // Default 5-touch sequence

    #[ORM\Column(type: 'json')]
    private array $touchTemplates = []; // Array of template IDs

    #[ORM\Column(type: 'boolean')]
    private bool $active = true;

    #[ORM\OneToMany(mappedBy: 'campaign', targetEntity: EmailSend::class, cascade: ['persist', 'remove'])]
    private Collection $sends;

    public function __construct()
    {
        $this->sends = new ArrayCollection();
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

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function setLanguage(string $language): self
    {
        $this->language = $language;
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

    public function getTouchCount(): ?int
    {
        return $this->touchCount;
    }

    public function setTouchCount(int $touchCount): self
    {
        $this->touchCount = $touchCount;
        return $this;
    }

    public function getTouchTemplates(): array
    {
        return $this->touchTemplates;
    }

    public function setTouchTemplates(array $touchTemplates): self
    {
        $this->touchTemplates = $touchTemplates;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;
        return $this;
    }

    /**
     * @return Collection<int, EmailSend>
     */
    public function getSends(): Collection
    {
        return $this->sends;
    }
}
