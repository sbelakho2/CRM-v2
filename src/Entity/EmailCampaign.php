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

    #[ORM\Column(type: 'json')]
    private array $abTestVariants = [];

    #[ORM\Column(type: 'boolean')]
    private bool $active = false;

    #[ORM\OneToMany(mappedBy: 'campaign', targetEntity: EmailSend::class, cascade: ['persist', 'remove'])]
    private Collection $emailSends;

    #[ORM\ManyToMany(targetEntity: Contact::class, mappedBy: 'emailCampaigns')]
    private Collection $contacts;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $scheduledAt = null;

    #[ORM\ManyToOne(targetEntity: EmailTemplate::class)]
    private ?EmailTemplate $template = null;

    public function __construct()
    {
        $this->emailSends = new ArrayCollection();
        $this->contacts = new ArrayCollection();
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
    public function getEmailSends(): Collection
    {
        return $this->emailSends;
    }

    public function addEmailSend(EmailSend $send): self
    {
        if (!$this->emailSends->contains($send)) {
            $this->emailSends->add($send);
            $send->setCampaign($this);
        }

        return $this;
    }

    public function removeEmailSend(EmailSend $send): self
    {
        if ($this->emailSends->removeElement($send)) {
            if ($send->getCampaign() === $this) {
                $send->setCampaign(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Contact>
     */
    public function getContacts(): Collection
    {
        return $this->contacts;
    }

    public function addContact(Contact $contact): self
    {
        if (!$this->contacts->contains($contact)) {
            $this->contacts->add($contact);
        }

        return $this;
    }

    public function removeContact(Contact $contact): self
    {
        $this->contacts->removeElement($contact);
        return $this;
    }

    public function getScheduledAt(): ?\DateTimeInterface
    {
        return $this->scheduledAt;
    }

    public function setScheduledAt(?\DateTimeInterface $scheduledAt): self
    {
        $this->scheduledAt = $scheduledAt;
        return $this;
    }

    public function getTemplate(): ?EmailTemplate
    {
        return $this->template;
    }

    public function setTemplate(?EmailTemplate $template): self
    {
        $this->template = $template;
        return $this;
    }

    /**
     * A/B test variant configurations
     *
     * @return array<int, mixed>
     */
    public function getAbTestVariants(): array
    {
        return $this->abTestVariants;
    }

    public function setAbTestVariants(array $variants): self
    {
        $this->abTestVariants = $variants;
        return $this;
    }
}
