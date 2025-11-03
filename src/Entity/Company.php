<?php

namespace App\Entity;

use App\Repository\CompanyRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CompanyRepository::class)]
#[ORM\Table(name: 'companies')]
class Company
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $sector = null; // Automotive, Industrial, Aerospace, Rail, Renewables, Power Electronics

    #[ORM\Column(length: 10)]
    private ?string $accountTier = 'C'; // A, B, C

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $region = null; // TAC, TFZ, AFZ Kenitra, Casablanca/Midparc, Bouskoura

    #[ORM\Column(length: 50)]
    private ?string $pipelineStage = 'Prospect'; // Prospect, MQL, SQL, SQO, Proposal, Award

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $website = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $address = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $city = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $country = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $linkedInUrl = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $physicalSite = null; // TAC, TFZ, AFZ Kenitra, Midparc, Bouskoura

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $linkedinCompanyUrl = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $sourceNotes = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $legalName = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $googleDriveLink = null;

    #[ORM\OneToMany(mappedBy: 'company', targetEntity: Contact::class, cascade: ['persist', 'remove'])]
    private Collection $contacts;

    #[ORM\OneToMany(mappedBy: 'company', targetEntity: Activity::class, cascade: ['persist', 'remove'])]
    private Collection $activities;

    #[ORM\OneToMany(mappedBy: 'company', targetEntity: RFQ::class, cascade: ['persist', 'remove'])]
    private Collection $rfqs;

    #[ORM\OneToOne(mappedBy: 'company', targetEntity: SupplierPortal::class, cascade: ['persist', 'remove'])]
    private ?SupplierPortal $supplierPortal = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->contacts = new ArrayCollection();
        $this->activities = new ArrayCollection();
        $this->rfqs = new ArrayCollection();
        $this->createdAt = new \DateTime();
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

    public function getSector(): ?string
    {
        return $this->sector;
    }

    public function setSector(string $sector): self
    {
        $this->sector = $sector;
        return $this;
    }

    public function getAccountTier(): ?string
    {
        return $this->accountTier;
    }

    public function setAccountTier(string $accountTier): self
    {
        $this->accountTier = $accountTier;
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

    public function getPipelineStage(): ?string
    {
        return $this->pipelineStage;
    }

    public function setPipelineStage(string $pipelineStage): self
    {
        $this->pipelineStage = $pipelineStage;
        return $this;
    }

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(?string $website): self
    {
        $this->website = $website;
        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): self
    {
        $this->address = $address;
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

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function setCountry(?string $country): self
    {
        $this->country = $country;
        return $this;
    }

    public function getLinkedInUrl(): ?string
    {
        return $this->linkedInUrl;
    }

    public function setLinkedInUrl(?string $linkedInUrl): self
    {
        $this->linkedInUrl = $linkedInUrl;
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
            $contact->setCompany($this);
        }
        return $this;
    }

    public function removeContact(Contact $contact): self
    {
        if ($this->contacts->removeElement($contact)) {
            if ($contact->getCompany() === $this) {
                $contact->setCompany(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, Activity>
     */
    public function getActivities(): Collection
    {
        return $this->activities;
    }

    /**
     * @return Collection<int, RFQ>
     */
    public function getRfqs(): Collection
    {
        return $this->rfqs;
    }

    public function getSupplierPortal(): ?SupplierPortal
    {
        return $this->supplierPortal;
    }

    public function setSupplierPortal(?SupplierPortal $supplierPortal): self
    {
        if ($supplierPortal === null && $this->supplierPortal !== null) {
            $this->supplierPortal->setCompany(null);
        }

        if ($supplierPortal !== null && $supplierPortal->getCompany() !== $this) {
            $supplierPortal->setCompany($this);
        }

        $this->supplierPortal = $supplierPortal;
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

    public function getPhysicalSite(): ?string
    {
        return $this->physicalSite;
    }

    public function setPhysicalSite(?string $physicalSite): self
    {
        $this->physicalSite = $physicalSite;
        return $this;
    }

    public function getLinkedinCompanyUrl(): ?string
    {
        return $this->linkedinCompanyUrl;
    }

    public function setLinkedinCompanyUrl(?string $linkedinCompanyUrl): self
    {
        $this->linkedinCompanyUrl = $linkedinCompanyUrl;
        return $this;
    }

    public function getSourceNotes(): ?string
    {
        return $this->sourceNotes;
    }

    public function setSourceNotes(?string $sourceNotes): self
    {
        $this->sourceNotes = $sourceNotes;
        return $this;
    }

    public function getLegalName(): ?string
    {
        return $this->legalName;
    }

    public function setLegalName(?string $legalName): self
    {
        $this->legalName = $legalName;
        return $this;
    }

    public function getGoogleDriveLink(): ?string
    {
        return $this->googleDriveLink;
    }

    public function setGoogleDriveLink(?string $googleDriveLink): self
    {
        $this->googleDriveLink = $googleDriveLink;
        return $this;
    }
}
