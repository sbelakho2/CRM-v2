<?php

namespace App\Entity;

use App\Repository\SupplierPortalRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SupplierPortalRepository::class)]
#[ORM\Table(name: 'supplier_portals')]
class SupplierPortal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
// Protected (not private): Doctrine assigns the identifier via reflection
    // on hydration, so static analysis never sees an int assignment.
    protected ?int $id = null;

    #[ORM\OneToOne(targetEntity: Company::class, inversedBy: 'supplierPortal')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Company $company = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $registered = false;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $registrationDate = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $portalUsername = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $profileCompleted = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $portalUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $portalId = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $submittedDate = null;

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $approvalDate = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $buyerName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $buyerEmail = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $buyerContacted = false;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $portalVendor = null; // ARIBA, COUPA, SAP_SRM, CUSTOM...

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $loginUrl = null;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $submitUrl = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $formFieldsJson = null; // Map of form field names to pack fields

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $requiresFileUpload = false;

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

    public function isRegistered(): bool
    {
        return $this->registered;
    }

    public function setRegistered(bool $registered): self
    {
        $this->registered = $registered;
        return $this;
    }

    public function getRegistrationDate(): ?\DateTimeInterface
    {
        return $this->registrationDate;
    }

    public function setRegistrationDate(?\DateTimeInterface $registrationDate): self
    {
        $this->registrationDate = $registrationDate;
        return $this;
    }

    public function getPortalUsername(): ?string
    {
        return $this->portalUsername;
    }

    public function setPortalUsername(?string $portalUsername): self
    {
        $this->portalUsername = $portalUsername;
        return $this;
    }

    public function isProfileCompleted(): bool
    {
        return $this->profileCompleted;
    }

    public function setProfileCompleted(bool $profileCompleted): self
    {
        $this->profileCompleted = $profileCompleted;
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

    public function getPortalUrl(): ?string
    {
        return $this->portalUrl;
    }

    public function setPortalUrl(?string $portalUrl): self
    {
        $this->portalUrl = $portalUrl;
        return $this;
    }

    public function getPortalId(): ?string
    {
        return $this->portalId;
    }

    public function setPortalId(?string $portalId): self
    {
        $this->portalId = $portalId;
        return $this;
    }

    public function getSubmittedDate(): ?\DateTimeInterface
    {
        return $this->submittedDate;
    }

    public function setSubmittedDate(?\DateTimeInterface $submittedDate): self
    {
        $this->submittedDate = $submittedDate;
        return $this;
    }

    public function getApprovalDate(): ?\DateTimeInterface
    {
        return $this->approvalDate;
    }

    public function setApprovalDate(?\DateTimeInterface $approvalDate): self
    {
        $this->approvalDate = $approvalDate;
        return $this;
    }

    public function getBuyerName(): ?string
    {
        return $this->buyerName;
    }

    public function setBuyerName(?string $buyerName): self
    {
        $this->buyerName = $buyerName;
        return $this;
    }

    public function getBuyerEmail(): ?string
    {
        return $this->buyerEmail;
    }

    public function setBuyerEmail(?string $buyerEmail): self
    {
        $this->buyerEmail = $buyerEmail;
        return $this;
    }

    public function isBuyerContacted(): bool
    {
        return $this->buyerContacted;
    }

    public function setBuyerContacted(bool $buyerContacted): self
    {
        $this->buyerContacted = $buyerContacted;
        return $this;
    }
    public function getPortalVendor(): ?string
    {
        return $this->portalVendor;
    }

    public function setPortalVendor(?string $portalVendor): self
    {
        $this->portalVendor = $portalVendor;

        return $this;
    }

    public function getLoginUrl(): ?string
    {
        return $this->loginUrl;
    }

    public function setLoginUrl(?string $loginUrl): self
    {
        $this->loginUrl = $loginUrl;

        return $this;
    }

    public function getSubmitUrl(): ?string
    {
        return $this->submitUrl;
    }

    public function setSubmitUrl(?string $submitUrl): self
    {
        $this->submitUrl = $submitUrl;

        return $this;
    }

    public function getFormFieldsJson(): ?string
    {
        return $this->formFieldsJson;
    }

    public function setFormFieldsJson(?string $formFieldsJson): self
    {
        $this->formFieldsJson = $formFieldsJson;

        return $this;
    }

    public function getRequiresFileUpload(): bool
    {
        return $this->requiresFileUpload;
    }

    public function setRequiresFileUpload(bool $requiresFileUpload): self
    {
        $this->requiresFileUpload = $requiresFileUpload;

        return $this;
    }

}
