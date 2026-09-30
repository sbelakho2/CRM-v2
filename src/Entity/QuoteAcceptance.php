<?php

namespace App\Entity;

use App\Repository\QuoteAcceptanceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Durable acceptance record for a live-quote acceptance: WHO accepted
 * (identity + PO number), WHAT pricing was accepted (immutable snapshot of
 * quantity/total/currency at acceptance time) and HOW (token fingerprint).
 * Created in the SAME transaction as the quote state transition.
 */
#[ORM\Entity(repositoryClass: QuoteAcceptanceRepository::class)]
#[ORM\Table(name: 'quote_acceptances')]
#[ORM\Index(name: 'idx_qa_quote', columns: ['quote_id'])]
#[ORM\UniqueConstraint(name: 'uniq_qa_quote', columns: ['quote_id'])]
class QuoteAcceptance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Quote::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?Quote $quote = null;

    #[ORM\Column(type: 'integer')]
    private ?int $acceptedQuantity = null;

    #[ORM\Column(type: 'decimal', precision: 15, scale: 2)]
    private ?string $acceptedTotal = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $currency = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $customerName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $customerEmail = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $customerPhone = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $customerCompany = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $poNumber = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $tokenFingerprint = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $pricingSnapshot = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $acceptedAt = null;

    public function __construct()
    {
        $this->acceptedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuote(): ?Quote
    {
        return $this->quote;
    }

    public function setQuote(?Quote $quote): self
    {
        $this->quote = $quote;

        return $this;
    }

    public function getAcceptedQuantity(): ?int
    {
        return $this->acceptedQuantity;
    }

    public function setAcceptedQuantity(int $acceptedQuantity): self
    {
        $this->acceptedQuantity = $acceptedQuantity;

        return $this;
    }

    public function getAcceptedTotal(): ?string
    {
        return $this->acceptedTotal;
    }

    public function setAcceptedTotal(?string $acceptedTotal): self
    {
        $this->acceptedTotal = $acceptedTotal;

        return $this;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(?string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    public function getCustomerName(): ?string
    {
        return $this->customerName;
    }

    public function setCustomerName(?string $customerName): self
    {
        $this->customerName = $customerName;

        return $this;
    }

    public function getCustomerEmail(): ?string
    {
        return $this->customerEmail;
    }

    public function setCustomerEmail(?string $customerEmail): self
    {
        $this->customerEmail = $customerEmail;

        return $this;
    }

    public function getCustomerPhone(): ?string
    {
        return $this->customerPhone;
    }

    public function setCustomerPhone(?string $customerPhone): self
    {
        $this->customerPhone = $customerPhone;

        return $this;
    }

    public function getCustomerCompany(): ?string
    {
        return $this->customerCompany;
    }

    public function setCustomerCompany(?string $customerCompany): self
    {
        $this->customerCompany = $customerCompany;

        return $this;
    }

    public function getPoNumber(): ?string
    {
        return $this->poNumber;
    }

    public function setPoNumber(?string $poNumber): self
    {
        $this->poNumber = $poNumber;

        return $this;
    }

    public function getTokenFingerprint(): ?string
    {
        return $this->tokenFingerprint;
    }

    public function setTokenFingerprint(?string $tokenFingerprint): self
    {
        $this->tokenFingerprint = $tokenFingerprint;

        return $this;
    }

    public function getPricingSnapshot(): ?array
    {
        return $this->pricingSnapshot;
    }

    public function setPricingSnapshot(?array $pricingSnapshot): self
    {
        $this->pricingSnapshot = $pricingSnapshot;

        return $this;
    }

    public function getAcceptedAt(): ?\DateTimeInterface
    {
        return $this->acceptedAt;
    }
}
