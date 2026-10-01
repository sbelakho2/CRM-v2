<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;

    #[ORM\Column]
    /** @var array<string, mixed>|list<mixed> $roles */
    private array $roles = [];

    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column(length: 100)]
    private ?string $firstName = null;

    #[ORM\Column(length: 100)]
    private ?string $lastName = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $role = null; // Field Rep, Digital Rep, Sales Ops, Engineering, Admin

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $territory = null; // Morocco, EU, Global

    #[ORM\Column(type: 'boolean')]
    private bool $active = true;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $isVerified = false;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $resetToken = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $resetTokenExpiresAt = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $displayCurrency = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $preferredLocale = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $preferredTimezone = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $preferredTheme = null; // system, light, dark

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $accentColor = null; // orange, blue, green, purple, red

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $fontSize = null; // small, medium, large

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $density = null; // comfortable, compact

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $reducedMotion = false;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $deactivatedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $deactivatedBy = null;

    // History-preserving: no cascade remove/orphanRemoval — deleting a user
    // would cascade-destroy their activities. Users are deactivated, not deleted.
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Activity::class, cascade: ['persist'])]
    private Collection $activities;

    public function __construct()
    {
        $this->activities = new ArrayCollection();
        // Users created outside the self-registration flow (commands, admin panel)
        // are trusted and therefore verified by default; registration explicitly
        // marks new accounts as unverified.
        $this->isVerified = true;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;
        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    public function setRoles(array $roles): self
    {
        $this->roles = $roles;
        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;
        return $this;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): self
    {
        $this->firstName = $firstName;
        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): self
    {
        $this->lastName = $lastName;
        return $this;
    }

    public function getFullName(): string
    {
        return $this->firstName . ' ' . $this->lastName;
    }

    public function getDisplayCurrency(): ?string
    {
        return $this->displayCurrency;
    }

    public function setDisplayCurrency(?string $displayCurrency): self
    {
        $this->displayCurrency = $displayCurrency ? strtoupper($displayCurrency) : null;
        return $this;
    }

    public function getPreferredLocale(): ?string
    {
        return $this->preferredLocale;
    }

    public function setPreferredLocale(?string $preferredLocale): self
    {
        $this->preferredLocale = $preferredLocale ? strtolower($preferredLocale) : null;
        return $this;
    }

    public function getPreferredTimezone(): ?string
    {
        return $this->preferredTimezone;
    }

    public function setPreferredTimezone(?string $preferredTimezone): self
    {
        $this->preferredTimezone = $preferredTimezone;
        return $this;
    }

    public function getPreferredTheme(): ?string
    {
        return $this->preferredTheme;
    }

    public function setPreferredTheme(?string $preferredTheme): self
    {
        $this->preferredTheme = $preferredTheme ? strtolower($preferredTheme) : null;
        return $this;
    }

    public function getAccentColor(): ?string
    {
        return $this->accentColor;
    }

    public function setAccentColor(?string $accentColor): self
    {
        $this->accentColor = $accentColor;
        return $this;
    }

    public function getFontSize(): ?string
    {
        return $this->fontSize;
    }

    public function setFontSize(?string $fontSize): self
    {
        $this->fontSize = $fontSize;
        return $this;
    }

    public function getDensity(): ?string
    {
        return $this->density;
    }

    public function setDensity(?string $density): self
    {
        $this->density = $density;
        return $this;
    }

    public function isReducedMotion(): bool
    {
        return $this->reducedMotion;
    }

    public function setReducedMotion(bool $reducedMotion): self
    {
        $this->reducedMotion = $reducedMotion;
        return $this;
    }

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function setRole(?string $role): self
    {
        $this->role = $role;
        return $this;
    }

    public function getTerritory(): ?string
    {
        return $this->territory;
    }

    public function setTerritory(?string $territory): self
    {
        $this->territory = $territory;
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
     * Deactivate instead of deleting: the user row (and every historical
     * foreign key pointing at it) is preserved forever for attribution.
     */
    public function deactivate(self $by): self
    {
        $this->active = false;
        $this->deactivatedAt = $this->deactivatedAt ?? new \DateTime();
        $this->deactivatedBy = $by;

        return $this;
    }

    public function reactivate(): self
    {
        $this->active = true;
        $this->deactivatedAt = null;
        $this->deactivatedBy = null;

        return $this;
    }

    /**
     * Erase personal data while keeping the row and its primary key intact,
     * so historical records keep pointing at a valid (anonymous) user.
     */
    public function pseudonymize(): self
    {
        $this->firstName = 'Former';
        $this->lastName = 'User';
        $this->email = sprintf('deleted-%s@invalid.local', bin2hex(random_bytes(8)));
        $this->territory = null;
        $this->resetToken = null;
        $this->resetTokenExpiresAt = null;

        return $this;
    }

    public function getDeactivatedAt(): ?\DateTimeInterface
    {
        return $this->deactivatedAt;
    }

    public function getDeactivatedBy(): ?self
    {
        return $this->deactivatedBy;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): self
    {
        $this->isVerified = $isVerified;
        return $this;
    }

    /**
     * @return Collection<int, Activity>
     */
    public function getActivities(): Collection
    {
        return $this->activities;
    }

    public function getResetToken(): ?string
    {
        return $this->resetToken;
    }

    public function setResetToken(?string $resetToken): self
    {
        $this->resetToken = $resetToken;
        return $this;
    }

    public function getResetTokenExpiresAt(): ?\DateTimeInterface
    {
        return $this->resetTokenExpiresAt;
    }

    public function setResetTokenExpiresAt(?\DateTimeInterface $resetTokenExpiresAt): self
    {
        $this->resetTokenExpiresAt = $resetTokenExpiresAt;
        return $this;
    }

    public function isResetTokenValid(): bool
    {
        if (!$this->resetToken || !$this->resetTokenExpiresAt) {
            return false;
        }
        
        return $this->resetTokenExpiresAt > new \DateTime();
    }
}
